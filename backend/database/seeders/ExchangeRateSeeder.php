<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Database\Seeder;

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
