<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Salaries',
            'Rent',
            'Utilities',
            'Products / Materials',
            'Equipment',
            'Marketing',
            'Maintenance',
            'Transport',
            'Miscellaneous',
        ];

        foreach ($categories as $name) {
            ExpenseCategory::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'is_active' => true,
                ]
            );
        }
    }
}
