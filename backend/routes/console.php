<?php

use App\Jobs\ExpireUnclaimedPrizesJob;
use App\Jobs\MarcarApuestasVencidasJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ExpireUnclaimedPrizesJob)->dailyAt('01:00');
// REQ13/D5: vence apuestas `pendiente` sin resultado tras la ventana
// configurable (24 h default), relanzando la búsqueda (catch-up) antes.
Schedule::job(new MarcarApuestasVencidasJob)->dailyAt('02:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
