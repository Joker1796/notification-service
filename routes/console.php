<?php

use App\Console\Commands\RecoverStuckNotificationsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reset notifications stuck in "sent" state due to worker crashes.
Schedule::command(RecoverStuckNotificationsCommand::class)->everyFiveMinutes();
