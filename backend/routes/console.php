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

// Retención de auditoría (#89): archiva hot > 12 meses, poda archivos > 5 años
// y verifica checksums/cadena. Todos usan withoutOverlapping (mutex Redis).
Schedule::command('audit:archive --apply')->monthlyOn(1, '03:00')->withoutOverlapping();
Schedule::command('audit:prune --apply')->yearlyOn(1, 1, '04:00')->withoutOverlapping();
Schedule::command('audit:verify')->weeklyOn(1, '05:00')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
