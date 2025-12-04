<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceCategory;
use Illuminate\Support\Str;

class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Laser Hair Removal',
                'slug' => 'laser-hair-removal',
                'description' => 'All laser-based permanent hair reduction services.',
            ],
            [
                'name' => 'Facial Treatments',
                'slug' => 'facial-treatments',
                'description' => 'Professional skincare and facial treatments.',
            ],
            [
                'name' => 'Solarium',
                'slug' => 'solarium',
                'description' => 'Tanning and solarium sessions.',
            ],
        ];

        foreach ($categories as $cat) {
            ServiceCategory::updateOrCreate(
                ['slug' => $cat['slug']],
                [
                    'name'        => $cat['name'],
                    'description' => $cat['description'],
                    'is_active'   => true,
                    'image_path'  => null, // no image yet
                ]
            );
        }
    }
}
