<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BancaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company,
            'code' => $this->faker->unique()->bothify('BC###'),
            'active' => true,
        ];
    }
}
