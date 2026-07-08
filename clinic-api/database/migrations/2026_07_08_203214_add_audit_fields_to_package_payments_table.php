<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_payments', function (Blueprint $table) {
            $table->decimal('exchange_rate', 10, 4)->nullable()->after('currency');
            $table->decimal('amount_mkd', 12, 2)->nullable()->after('exchange_rate');

            $table->foreignId('voided_by_id')
                ->nullable()
                ->after('voided_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('void_reason')->nullable()->after('voided_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('package_payments', function (Blueprint $table) {
            $table->dropForeign(['voided_by_id']);
            $table->dropColumn([
                'exchange_rate',
                'amount_mkd',
                'voided_by_id',
                'void_reason',
            ]);
        });
    }
};