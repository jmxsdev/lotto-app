<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            UsersSeeder::class,
            ExchangeRateSeeder::class,
            JuegoAnimalitosSeeder::class,
            TripleZuliaSeeder::class,
            TerminalesSeeder::class,
            LottoActivoRDSeeder::class,
            LottoActivoRDSDSeeder::class,
            MonjeMillonarioSeeder::class,
            TrioActivoSeeder::class,
        ]);
    }
}
