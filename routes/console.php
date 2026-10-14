<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Badges and CPN prices depend on today's date: rebuild static cache after midnight (Europe/Warsaw).
Schedule::command('statamic:static:clear')->dailyAt('00:05')->timezone('Europe/Warsaw');
