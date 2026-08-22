<?php

use App\Domain\Bookings\Jobs\HoldBookingExpiryJob;
use App\Domain\Identity\Models\PasswordResetOtp;
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

// Consumed/expired reset codes have no further use and only grow the table.
// Keep 24h of them for troubleshooting, then delete.
Schedule::call(function () {
    PasswordResetOtp::where('expires_at', '<', now()->subDay())
        ->orWhere('consumed_at', '<', now()->subDay())
        ->delete();
})->daily()->name('prune-password-reset-otps');
