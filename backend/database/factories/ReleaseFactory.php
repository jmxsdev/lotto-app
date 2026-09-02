<?php

namespace Database\Factories;

use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = $this->faker->numerify('1.0.#');

        return [
            'version' => $version,
            'sha256' => hash('sha256', $this->faker->unique()->uuid()),
            'file_path' => "Taquilla-Setup-{$version}.exe",
            'file_size' => $this->faker->numberBetween(1024, 1024 * 1024),
            'published_at' => now(),
        ];
    }
}
