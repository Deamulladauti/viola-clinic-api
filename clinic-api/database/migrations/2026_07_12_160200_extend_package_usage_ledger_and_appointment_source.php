<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'source')) {
                $table->string('source', 30)->default('legacy')->after('status');
                $table->index('source');
            }
        });

        Schema::table('package_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('package_logs', 'active_appointment_id')) {
                $table->unsignedBigInteger('active_appointment_id')->nullable()->after('appointment_id');
                $table->unique('active_appointment_id', 'package_logs_active_appointment_unique');
            }

            if (! Schema::hasColumn('package_logs', 'usage_type')) {
                $table->string('usage_type', 20)->nullable()->after('appointment_ref');
            }

            if (! Schema::hasColumn('package_logs', 'quantity')) {
                $table->unsignedInteger('quantity')->default(0)->after('usage_type');
            }

            if (! Schema::hasColumn('package_logs', 'session_number')) {
                $table->unsignedInteger('session_number')->nullable()->after('quantity');
            }

            if (! Schema::hasColumn('package_logs', 'occurred_on')) {
                $table->date('occurred_on')->nullable()->after('used_at');
            }

            if (! Schema::hasColumn('package_logs', 'source')) {
                $table->string('source', 20)->default('manual')->after('occurred_on');
            }

            if (! Schema::hasColumn('package_logs', 'created_by_id')) {
                $table->unsignedBigInteger('created_by_id')->nullable()->after('source');
                $table->index('created_by_id');
            }

            if (! Schema::hasColumn('package_logs', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('note');
            }

            if (! Schema::hasColumn('package_logs', 'voided_by_id')) {
                $table->unsignedBigInteger('voided_by_id')->nullable()->after('voided_at');
                $table->index('voided_by_id');
            }

            if (! Schema::hasColumn('package_logs', 'void_reason')) {
                $table->text('void_reason')->nullable()->after('voided_by_id');
            }
        });

        DB::table('package_logs')
            ->whereNull('usage_type')
            ->where('used_sessions', '>', 0)
            ->update([
                'usage_type' => 'session',
                'quantity' => DB::raw('used_sessions'),
                'source' => 'imported',
            ]);

        DB::table('package_logs')
            ->whereNull('usage_type')
            ->where('used_minutes', '>', 0)
            ->update([
                'usage_type' => 'minutes',
                'quantity' => DB::raw('used_minutes'),
                'source' => 'imported',
            ]);

        DB::table('package_logs')
            ->whereNull('occurred_on')
            ->whereNotNull('used_at')
            ->update(['occurred_on' => DB::raw('DATE(used_at)')]);

        // Backfill the unique active key for one existing usage per appointment.
        DB::table('package_logs')
            ->whereNotNull('appointment_id')
            ->whereNull('voided_at')
            ->orderBy('id')
            ->get()
            ->groupBy('appointment_id')
            ->each(function ($logs, $appointmentId) {
                $first = $logs->first();
                DB::table('package_logs')
                    ->where('id', $first->id)
                    ->update(['active_appointment_id' => (int) $appointmentId]);
            });
    }

    public function down(): void
    {
        Schema::table('package_logs', function (Blueprint $table) {
            if (Schema::hasColumn('package_logs', 'active_appointment_id')) {
                try {
                    $table->dropUnique('package_logs_active_appointment_unique');
                } catch (Throwable) {
                    // Some SQLite installations rebuild indexes differently.
                }
            }

            foreach (['created_by_id', 'voided_by_id'] as $column) {
                if (Schema::hasColumn('package_logs', $column)) {
                    try {
                        $table->dropIndex([$column]);
                    } catch (Throwable) {
                        // Ignore driver-specific index names during rollback.
                    }
                }
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('package_logs', 'active_appointment_id') ? 'active_appointment_id' : null,
                Schema::hasColumn('package_logs', 'usage_type') ? 'usage_type' : null,
                Schema::hasColumn('package_logs', 'quantity') ? 'quantity' : null,
                Schema::hasColumn('package_logs', 'session_number') ? 'session_number' : null,
                Schema::hasColumn('package_logs', 'occurred_on') ? 'occurred_on' : null,
                Schema::hasColumn('package_logs', 'source') ? 'source' : null,
                Schema::hasColumn('package_logs', 'created_by_id') ? 'created_by_id' : null,
                Schema::hasColumn('package_logs', 'voided_at') ? 'voided_at' : null,
                Schema::hasColumn('package_logs', 'voided_by_id') ? 'voided_by_id' : null,
                Schema::hasColumn('package_logs', 'void_reason') ? 'void_reason' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'source')) {
                try {
                    $table->dropIndex(['source']);
                } catch (Throwable) {
                    // Ignore driver-specific index names during rollback.
                }
                $table->dropColumn('source');
            }
        });
    }
};
