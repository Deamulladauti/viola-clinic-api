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
            $table->decimal('sale_original_price', 10, 2)->nullable()->after('price');
            $table->string('sale_discount_type', 30)->nullable()->after('sale_original_price');
            $table->decimal('sale_discount_value', 10, 2)->nullable()->after('sale_discount_type');
            $table->decimal('sale_discount_amount', 10, 2)->default(0)->after('sale_discount_value');
            $table->decimal('sale_final_price', 10, 2)->nullable()->after('sale_discount_amount');
        });

        Schema::table('service_packages', function (Blueprint $table) {
            $table->decimal('sale_original_price', 10, 2)->nullable()->after('price_total');
            $table->string('sale_discount_type', 30)->nullable()->after('sale_original_price');
            $table->decimal('sale_discount_value', 10, 2)->nullable()->after('sale_discount_type');
            $table->decimal('sale_discount_amount', 10, 2)->default(0)->after('sale_discount_value');
            $table->decimal('sale_final_price', 10, 2)->nullable()->after('sale_discount_amount');
        });

        // Existing appointments already have a historical booking-time price.
        // Preserve it as both original and final rather than looking at today's
        // service price, which could rewrite history.
        DB::table('appointments')->update([
            'sale_original_price' => DB::raw('price'),
            'sale_discount_type' => null,
            'sale_discount_value' => null,
            'sale_discount_amount' => 0,
            'sale_final_price' => DB::raw('price'),
        ]);

        // Existing package totals are also treated as the historical agreed
        // amount. We intentionally do not infer an old list price from the
        // current service record. Task 11 is the controlled historical
        // correction path for legacy promotional purchases.
        $packages = DB::table('service_packages')
            ->select(['id', 'price_total', 'price_paid'])
            ->get();

        foreach ($packages as $package) {
            $legacyTotal = $package->price_total ?? $package->price_paid ?? 0;

            DB::table('service_packages')
                ->where('id', $package->id)
                ->update([
                    'sale_original_price' => $legacyTotal,
                    'sale_discount_type' => null,
                    'sale_discount_value' => null,
                    'sale_discount_amount' => 0,
                    'sale_final_price' => $legacyTotal,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'sale_original_price',
                'sale_discount_type',
                'sale_discount_value',
                'sale_discount_amount',
                'sale_final_price',
            ]);
        });

        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropColumn([
                'sale_original_price',
                'sale_discount_type',
                'sale_discount_value',
                'sale_discount_amount',
                'sale_final_price',
            ]);
        });
    }
};
