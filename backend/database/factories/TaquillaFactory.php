<?php

namespace Database\Factories;

use App\Models\Agencia;
use App\Models\Grupo;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaquillaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->word.' Taquilla',
            'code' => $this->faker->unique()->bothify('T###'),
            'grupo_id' => Grupo::factory(), // crea un grupo automáticamente si no se pasa
            'agencia_id' => null,
            'mac_address' => $this->faker->macAddress,
            'activation_code' => $this->faker->unique()->bothify('AC###'),
            'active' => true,
            'last_connection_at' => null,
        ];
    }

    /**
     * Vincula la taquilla a un local (agencia). Si no se pasa, crea uno
     * para el grupo de la taquilla (1 local por grupo en tests).
     */
    public function forAgencia(?Agencia $agencia = null): static
    {
        return $this->state(function (array $attributes) use ($agencia) {
            $grupoId = $attributes['grupo_id'] ?? Grupo::factory()->create()->id;
            $agencia = $agencia ?? Agencia::firstOrCreate(
                ['grupo_id' => $grupoId],
                ['name' => 'Local Test', 'code' => 'AG-'.strtoupper(uniqid())]
            );

            return [
                'agencia_id' => $agencia->id,
                'grupo_id' => $agencia->grupo_id,
            ];
        });
    }
}
