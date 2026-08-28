<?php

namespace Database\Factories;

use App\Models\Grupo;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgenciaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->word.' Local',
            'code' => $this->faker->unique()->bothify('AG###'),
            'grupo_id' => Grupo::factory(), // crea un grupo automáticamente si no se pasa
            'active' => true,
        ];
    }
}
