<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tag;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            ['name' => 'Laser',          'slug' => 'laser'],
            ['name' => 'Facial',         'slug' => 'facial'],
            ['name' => 'Solarium',       'slug' => 'solarium'],
            ['name' => 'Package',        'slug' => 'package'],
            ['name' => 'Single Session', 'slug' => 'single-session'],
            ['name' => 'Premium',        'slug' => 'premium'],
        ];

        foreach ($tags as $tag) {
            Tag::updateOrCreate(
                ['slug' => $tag['slug']],
                ['name' => $tag['name']]
            );
        }
    }
}
