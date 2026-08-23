<?php

namespace App\Domain\Identity\Support;

use App\Domain\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which user rows an actor may see and act on.
 *
 * `User` is deliberately not a `BelongsToTenant` model — a Super Admin has no
 * hotel and a Chain Admin has no single one, so a global scope keyed on
 * `hotel_id` would fail closed for exactly the accounts that need to see the
 * most. Scoping therefore happens here, explicitly, and every query in the
 * User Management controllers must start from `query()`.
 */
final class UserDirectory
{
    /**
     * @return Builder<User>
     */
    public static function query(User $actor): Builder
    {
        if ($actor->hasRole('super_admin')) {
            return User::query();
        }

        if ($actor->hasRole('hotel_chain_admin') && $actor->chain_id !== null) {
            $hotelIds = Hotel::where('chain_id', $actor->chain_id)->pluck('id');

            return User::query()->where(function (Builder $q) use ($hotelIds, $actor) {
                $q->whereIn('hotel_id', $hotelIds)->orWhere('chain_id', $actor->chain_id);
            });
        }

        if ($actor->hotel_id !== null) {
            return User::query()->where('hotel_id', $actor->hotel_id);
        }

        // An account with neither a hotel nor a chain sees nobody, rather than
        // everybody. Same fail-closed rule as TenantContext.
        return User::query()->whereRaw('1 = 0');
    }

    public static function isVisibleTo(User $actor, User $target): bool
    {
        return self::query($actor)->whereKey($target->getKey())->exists();
    }

    /**
     * Managing a row needs more than seeing it: the actor must also outrank
     * the target, and may never act on their own account here (that would let
     * a Hotel Admin grant themselves permissions they were not given). Self
     * service lives at /auth/change-password instead.
     */
    public static function canManage(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        return self::isVisibleTo($actor, $target) && RoleHierarchy::outranks($actor, $target);
    }

    /**
     * Permissions an actor may hand out — never more than they hold
     * themselves, so a Hotel Admin cannot grant `hotels.delete` just because
     * the name exists in the taxonomy.
     *
     * @return Collection<int, string>
     */
    public static function grantablePermissions(User $actor): Collection
    {
        return $actor->getAllPermissions()->pluck('name')->sort()->values();
    }

    /**
     * Hotel ids the actor may place a new account in. `null` means no limit
     * (Super Admin); an empty array means they may not name a hotel at all.
     *
     * @return array<int, int>|null
     */
    public static function assignableHotelIds(User $actor): ?array
    {
        if ($actor->hasRole('super_admin')) {
            return null;
        }

        if ($actor->chain_id !== null) {
            return Hotel::where('chain_id', $actor->chain_id)->pluck('id')->all();
        }

        return $actor->hotel_id !== null ? [$actor->hotel_id] : [];
    }

    public static function canPlaceUserIn(User $actor, int $hotelId): bool
    {
        $allowed = self::assignableHotelIds($actor);

        return $allowed === null || in_array($hotelId, $allowed, true);
    }

    /**
     * Where a newly created account belongs.
     *
     * A Hotel Admin creates inside their own hotel and cannot plant an
     * account in someone else's property. A Chain Admin has no single
     * `hotel_id` of their own, so they must name one of their chain's hotels
     * — validated before this runs; without a hotel the new account is
     * chain-level and inherits `chain_id` instead. Returning a bare
     * `$actor->hotel_id` for them, as an earlier version did, created
     * accounts with neither hotel nor chain: invisible to everyone including
     * the admin who had just made them.
     *
     * @return array{hotel_id: int|null, chain_id: int|null}
     */
    public static function tenancyForNewUser(User $actor, ?int $requestedHotelId): array
    {
        if ($actor->hasRole('super_admin')) {
            return ['hotel_id' => $requestedHotelId, 'chain_id' => null];
        }

        if ($actor->chain_id !== null) {
            return $requestedHotelId !== null
                ? ['hotel_id' => $requestedHotelId, 'chain_id' => null]
                : ['hotel_id' => null, 'chain_id' => $actor->chain_id];
        }

        return ['hotel_id' => $actor->hotel_id, 'chain_id' => null];
    }
}
