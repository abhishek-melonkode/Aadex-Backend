<?php

namespace App\Domain\Identity\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Who outranks whom, read from `roles.level` rather than a list in code.
 *
 * User management is the one place where a bug quietly becomes privilege
 * escalation: without a hierarchy any account holding `users.create` could
 * mint itself a `super_admin`. The rule is deliberately strict — you may only
 * act on, or hand out, a rank strictly *below* your own. Never your own, so
 * two Hotel Admins cannot re-role each other, and no one can delete the rank
 * they themselves depend on.
 *
 * Levels live on the row so roles created through the API take part in the
 * same rules. Lower number outranks higher; an unset level defaults to 100,
 * i.e. the bottom, which is the safe direction to fail.
 */
final class RoleHierarchy
{
    public const LOWEST_LEVEL = 100;

    /**
     * Resolved once per request. Role rows change rarely, and re-reading them
     * for every permission check inside a loop is wasteful.
     *
     * @var Collection<string, int>|null
     */
    private static ?Collection $levels = null;

    /** @var Collection<int, string>|null */
    private static ?Collection $assignable = null;

    /**
     * Every role's rank, assignable or not — rank still decides who may edit
     * a role even when it can't be handed to an account.
     *
     * @return Collection<string, int>
     */
    public static function levels(): Collection
    {
        return self::$levels ??= Role::query()
            ->orderBy('level')
            ->pluck('level', 'name')
            ->map(fn ($level) => (int) $level);
    }

    /**
     * Role names flagged assignable in the database. A role can be excluded
     * without touching this class — see the `is_assignable` column.
     *
     * @return Collection<int, string>
     */
    private static function assignableNames(): Collection
    {
        return self::$assignable ??= Role::query()->where('is_assignable', true)->pluck('name');
    }

    /**
     * Call after any write to the roles table, so the next lookup in the same
     * request sees it. Mirrors spatie's own forgetCachedPermissions().
     */
    public static function forget(): void
    {
        self::$levels = null;
        self::$assignable = null;
    }

    public static function levelOf(User $user): int
    {
        $levels = self::levels();

        return collect($user->getRoleNames())
            ->map(fn (string $role) => $levels[$role] ?? self::LOWEST_LEVEL)
            ->min() ?? self::LOWEST_LEVEL;
    }

    public static function levelOfRole(string $role): int
    {
        return self::levels()[$role] ?? self::LOWEST_LEVEL;
    }

    /**
     * Roles $actor may grant or manage: strictly below their own level.
     *
     * @return array<int, string>
     */
    public static function assignableBy(User $actor): array
    {
        $actorLevel = self::levelOf($actor);

        $assignable = self::assignableNames();

        return self::levels()
            ->filter(fn (int $level, string $role) => $level > $actorLevel && $assignable->contains($role))
            ->keys()
            ->all();
    }

    public static function canAssign(User $actor, string $role): bool
    {
        return in_array($role, self::assignableBy($actor), true);
    }

    /** Whether $actor outranks $target. Equal rank is not enough. */
    public static function outranks(User $actor, User $target): bool
    {
        return self::levelOf($actor) < self::levelOf($target);
    }

    /**
     * The range of levels $actor may give a role they create or edit. They
     * cannot mint a peer or a superior, so the floor is one below their own.
     *
     * @return array{min: int, max: int}
     */
    public static function assignableLevelRange(User $actor): array
    {
        return ['min' => self::levelOf($actor) + 1, 'max' => self::LOWEST_LEVEL];
    }
}
