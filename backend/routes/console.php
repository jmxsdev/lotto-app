<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ExpireUnclaimedPrizesJob;
use App\Jobs\ScrapeExchangeRateJob;

Schedule::job(new ScrapeExchangeRateJob)->everySixHours();
Schedule::job(new ExpireUnclaimedPrizesJob)->dailyAt('01:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
