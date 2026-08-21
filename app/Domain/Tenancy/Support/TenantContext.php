<?php

namespace App\Domain\Tenancy\Support;

/**
 * Resolved tenant scope for the current request, bound into the container by
 * App\Http\Middleware\ResolveTenant. Three shapes: bypass (Super Admin, sees
 * everything), a single hotel (Property Admin/Staff/Guest), or a set of hotel
 * ids (Chain Admin, scoped to every property under their chain).
 */
class TenantContext
{
    private function __construct(
        public readonly bool $bypass = false,
        public readonly ?int $hotelId = null,
        public readonly array $hotelIds = [],
    ) {}

    public static function bypass(): self
    {
        return new self(bypass: true);
    }

    public static function forHotel(int $hotelId): self
    {
        return new self(hotelId: $hotelId);
    }

    public static function forHotels(array $hotelIds): self
    {
        return new self(hotelIds: array_values(array_unique($hotelIds)));
    }

    public function includesHotel(int $hotelId): bool
    {
        if ($this->bypass) {
            return true;
        }

        if ($this->hotelId !== null) {
            return $this->hotelId === $hotelId;
        }

        return in_array($hotelId, $this->hotelIds, true);
    }
}
