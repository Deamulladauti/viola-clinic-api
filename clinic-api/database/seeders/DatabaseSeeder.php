<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
            UsersAndStaffSeeder::class,
            ServiceCategorySeeder::class,
            ServiceSeeder::class,
            TagSeeder::class,
            ServiceTagSeeder::class,
            ServiceStaffSeeder::class,
        ]);
    }
}