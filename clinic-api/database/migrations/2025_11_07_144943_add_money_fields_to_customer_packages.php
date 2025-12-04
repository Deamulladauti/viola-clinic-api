<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            if (!Schema::hasColumn('service_packages', 'price_total')) {
                $table->decimal('price_total', 10, 2)->nullable()->after('currency');
            }
            if (!Schema::hasColumn('service_packages', 'amount_paid')) {
                $table->decimal('amount_paid', 10, 2)->default(0)->after('price_total');
            }
        });

        // Backfill amount_paid from legacy price_paid if present
        if (Schema::hasColumn('service_packages', 'price_paid')) {
            DB::table('service_packages')
              ->whereNull('amount_paid')
              ->update(['amount_paid' => DB::raw('price_paid')]);
            // Keep price_paid for now to avoid SQLite rename/drop headaches; stop using it in code.
        }
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            if (Schema::hasColumn('service_packages', 'amount_paid')) {
                $table->dropColumn('amount_paid');
            }
            if (Schema::hasColumn('service_packages', 'price_total')) {
                $table->dropColumn('price_total');
            }
        });
    }
};