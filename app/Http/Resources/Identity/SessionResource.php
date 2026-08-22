<?php

namespace App\Http\Resources\Identity;

use App\Domain\Identity\Models\LoginActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One active API session = one Sanctum personal access token, enriched with
 * the IP/user-agent captured by LoginActivityLog when that token was issued.
 *
 * @property-read PersonalAccessToken $resource
 */
class SessionResource extends JsonResource
{
    public function __construct(
        PersonalAccessToken $resource,
        private readonly ?LoginActivityLog $activity = null,
        private readonly bool $isCurrent = false,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'device_name' => $this->resource->name,
            'ip_address' => $this->activity?->ip_address,
            'user_agent' => $this->activity?->user_agent,
            'is_current' => $this->isCurrent,
            'is_impersonation' => $this->activity?->impersonated_by !== null,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'last_used_at' => $this->resource->last_used_at?->toIso8601String(),
            'expires_at' => self::effectiveExpiry($this->resource)?->toIso8601String(),
        ];
    }

    /**
     * Sanctum enforces the per-token `expires_at` AND the global
     * `sanctum.expiration` window, so the session really dies at whichever
     * comes first. Report that, not just the column.
     */
    public static function effectiveExpiry(PersonalAccessToken $token): ?\Illuminate\Support\Carbon
    {
        $window = config('sanctum.expiration');
        $fromWindow = $window ? $token->created_at?->copy()->addMinutes((int) $window) : null;

        return match (true) {
            $token->expires_at && $fromWindow => $token->expires_at->min($fromWindow),
            default => $token->expires_at ?? $fromWindow,
        };
    }
}
