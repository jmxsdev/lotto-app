<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ExchangeRate;
use App\Models\User;

class ExchangeRateSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'super@lotto.com')->first();

        if ($user) {
            ExchangeRate::create([
                'rate' => 36.50,
                'base_currency' => 'USD',
                'reference_date' => now(),
                'set_by' => $user->id,
                'notes' => 'Tasa inicial',
                'is_active' => true,
            ]);
        }
    }
}
