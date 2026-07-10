<?php

namespace Database\Factories;

use App\Models\Grupo;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaquillaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->word . ' Taquilla',
            'code' => $this->faker->unique()->bothify('T###'),
            'grupo_id' => Grupo::factory(), // crea un grupo automáticamente si no se pasa
            'mac_address' => $this->faker->macAddress,
            'activation_code' => $this->faker->unique()->bothify('AC###'),
            'active' => true,
            'last_connection_at' => null,
        ];
    }
}
