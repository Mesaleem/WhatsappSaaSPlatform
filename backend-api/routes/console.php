<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
// Auto-Budget Guard. Spec asked for "every 15-30 minutes" — 15 minutes
// chosen (the tighter end of that range) since the rule this guards
// against is uncontrolled ad spend; a shorter poll interval bounds the
// worst-case overspend window before an auto-pause fires. Laravel 11 has
// no app/Console/Kernel.php — this file (routes/console.php) is the
// framework's own replacement location for schedule registration.
// withoutOverlapping() guards against a slow Meta API response on one
// run still executing when the next 15-minute tick fires.
Schedule::command('ads:check-performance-rules')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
