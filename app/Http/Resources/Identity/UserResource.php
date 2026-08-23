<?php

namespace App\Http\Resources\Identity;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;

class UserResource extends JsonResource
{
    /** Set by withSources(); see the note on `permission_sources` below. */
    protected bool $withSources = false;

    public function toArray(Request $request): array
    {
        $effective = $this->getAllPermissions()->pluck('name');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'status' => $this->status,
            'hotel_id' => $this->hotel_id,
            'chain_id' => $this->chain_id,
            'roles' => $this->getRoleNames(),

            // Everything the user can do, role-derived and directly granted
            // merged together. This is the flat list to check a single ability
            // against.
            'permissions' => $effective,

            // The same set as {module: {action: bool}}, so a client can hide a
            // whole menu section with one lookup instead of scanning the flat
            // list. Only modules the user can touch at all appear.
            'abilities' => $this->abilities($effective),

            // Where each permission came from, so a User Management screen can
            // show which boxes are ticked by the role — and must not be
            // un-ticked per account — versus granted to this account directly.
            //
            // Opt-in via withSources(), not sniffed from the route: the routes
            // here are unnamed, so an earlier `routeIs('*users*')` check was
            // always false and this key never appeared at all.
            'permission_sources' => $this->when($this->withSources, fn () => [
                'via_role' => $this->getPermissionsViaRoles()->pluck('name')->sort()->values(),
                'direct' => $this->getDirectPermissions()->pluck('name')->sort()->values(),
            ]),
        ];
    }

    /**
     * Include the role-versus-direct breakdown. Only the User Management
     * screens need it; the auth responses would just carry the extra weight.
     */
    public function withSources(): static
    {
        $this->withSources = true;

        return $this;
    }

    /**
     * A whole list with the breakdown included.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\User>  $users
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function listWithSources($users)
    {
        return $users->map(fn ($user) => (new self($user))->withSources());
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $permissions
     * @return array<string, array<string, bool>>
     */
    private function abilities($permissions): array
    {
        return $permissions
            ->groupBy(fn (string $name) => explode('.', $name)[0])
            ->map(fn ($names) => $names
                ->mapWithKeys(fn (string $name) => [explode('.', $name)[1] => true])
                ->all())
            ->all();
    }

    /**
     * The whole taxonomy as {module: [actions]} — what a permission-matrix UI
     * needs to render the un-ticked boxes too, not just the ticked ones.
     *
     * @return array<string, array<int, string>>
     */
    public static function permissionCatalogue(): array
    {
        return Permission::orderBy('name')
            ->pluck('name')
            ->groupBy(fn (string $name) => explode('.', $name)[0])
            ->map(fn ($names) => $names->map(fn (string $n) => explode('.', $n)[1])->values()->all())
            ->all();
    }
}
