<?php

namespace Database\Factories;

use App\Models\Banca;
use Illuminate\Database\Eloquent\Factories\Factory;

class GrupoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->word.' Group',
            'code' => $this->faker->unique()->bothify('GP###'),
            'banca_id' => Banca::factory(), // crea una banca automáticamente si no se pasa
            'active' => true,
        ];
    }
}
