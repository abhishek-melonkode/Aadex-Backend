<?php

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class LoginActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'personal_access_token_id',
        'ip_address',
        'user_agent',
        'impersonated_by',
        'logged_in_at',
        'logged_out_at',
    ];

    protected $casts = [
        'logged_in_at' => 'datetime',
        'logged_out_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_by');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    /**
     * Stamp the logout time on the still-open record(s) for these tokens.
     * Call this before deleting the tokens themselves — the FK nulls out on
     * delete, so afterwards there is no way to find the right rows.
     *
     * @param  array<int, int>|int  $tokenIds
     */
    public static function closeForTokens(array|int $tokenIds): void
    {
        self::whereIn('personal_access_token_id', (array) $tokenIds)
            ->whereNull('logged_out_at')
            ->update(['logged_out_at' => now()]);
    }
}
