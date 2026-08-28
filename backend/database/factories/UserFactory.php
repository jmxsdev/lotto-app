<?php

namespace Database\Factories;

use App\Models\Agencia;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role' => 'taquilla', // por defecto
            'banca_id' => null,
            'grupo_id' => null,
            'taquilla_id' => null,
            'agencia_id' => null,
            'active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Usuario rol taquilla vinculado a una taquilla y su local (agencia).
     */
    public function forAgencia(?Taquilla $taquilla = null): static
    {
        return $this->state(function (array $attributes) use ($taquilla) {
            $taquilla = $taquilla ?? Taquilla::factory()->forAgencia()->create();

            return [
                'role' => 'taquilla',
                'taquilla_id' => $taquilla->id,
                'grupo_id' => $taquilla->grupo_id,
                'banca_id' => $taquilla->grupo->banca_id,
                'agencia_id' => $taquilla->agencia_id,
            ];
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
