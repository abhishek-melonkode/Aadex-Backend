<?php

use App\Domain\Bookings\Jobs\HoldBookingExpiryJob;
use App\Domain\SuperAdmin\Jobs\ZeroInventoryAlertJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new HoldBookingExpiryJob)->everyFiveMinutes();
Schedule::job(new ZeroInventoryAlertJob)->hourly();

// Sanctum only *rejects* expired tokens at auth time; the rows stay in
// personal_access_tokens forever unless pruned. Keep 7 days of expired
// tokens for audit, then delete.
Schedule::command('sanctum:prune-expired --hours=168')->daily();
