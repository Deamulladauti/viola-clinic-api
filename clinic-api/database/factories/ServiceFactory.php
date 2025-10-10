<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);
        return [
            'service_category_id' => ServiceCategory::inRandomOrder()->value('id') ?? ServiceCategory::factory(),
            'name' => ucfirst($name),
            'slug' => str($name)->slug() . '-' . $this->faker->unique()->bothify('??##'),
            'description' => $this->faker->optional()->paragraph(),
            'duration_minutes' => $this->faker->numberBetween(15, 180),
            'price' => $this->faker->randomFloat(2, 10, 500),
            'is_active' => true,
        ];
    }
}
