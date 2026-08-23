<?php

namespace App\Http\Controllers\Api\V1\Users;

use App\Domain\Identity\Support\RoleHierarchy;
use App\Domain\Identity\Support\UserDirectory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StorePermissionRequest;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Http\Resources\Identity\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and the permission taxonomy, managed at runtime.
 *
 * Nothing here is a fixed list: a Super Admin can add a role, place it in the
 * hierarchy, give it permissions, and add new permission names as new modules
 * appear. The seeder only supplies a starting point.
 *
 * Three rules keep that from becoming an escalation path, and each has a test
 * in RoleManagementTest:
 *
 *  - a role can only be created or edited *below* the caller's own rank, so
 *    nobody can mint a peer or a superior — which also means no one can edit
 *    or delete the role they themselves hold;
 *  - a role can never be given a permission its author does not hold;
 *  - a role still assigned to accounts cannot be deleted, so authority is
 *    never removed from under a live session by accident.
 *
 * Role *names* are immutable on purpose. Route middleware refers to them
 * (`role:hotel_admin`), so a rename at runtime would silently unguard every
 * route mentioning the old name.
 */
class RoleController extends Controller
{
    #[OA\Get(
        path: '/roles',
        summary: 'List roles, their rank and what each one grants',
        description: 'Ordered by rank, lowest number first. `assignable_by_you` marks the roles the caller may hand out or edit — always strictly below their own rank.',
        tags: ['Roles & Permissions'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Roles')]
    )]
    public function index(Request $request): JsonResponse
    {
        $assignable = RoleHierarchy::assignableBy($request->user());

        $holders = $this->holderCounts();

        $roles = Role::with('permissions')
            ->orderBy('level')
            ->get()
            ->map(fn (Role $role) => $this->present($role, $assignable, (int) ($holders[$role->id] ?? 0)));

        return response()->json([
            'data' => $roles,
            'meta' => [
                'your_level' => RoleHierarchy::levelOf($request->user()),
                'assignable_level_range' => RoleHierarchy::assignableLevelRange($request->user()),
            ],
        ]);
    }

    #[OA\Post(
        path: '/roles',
        summary: 'Create a role',
        description: 'The rank must be below the caller\'s own, and every permission must be one the caller already holds.',
        tags: ['Roles & Permissions'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name', 'level', 'permissions'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'front_office_manager', description: 'Lowercase, underscores; immutable once created'),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'level', type: 'integer', example: 25, description: 'Lower outranks higher; seeded roles sit at 0/10/20/30/40'),
                new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string', example: 'bookings.view')),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Role created'),
            new OA\Response(response: 422, description: 'Rank at or above the caller, or a permission they do not hold'),
        ]
    )]
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $actor = $request->user();
        $requested = collect($request->input('permissions', []));

        if ($refused = $this->refusedPermissions($actor, $requested)) {
            return $this->cannotGrant($refused);
        }

        $role = DB::transaction(function () use ($request, $requested) {
            $role = Role::create([
                'name' => $request->string('name')->toString(),
                'guard_name' => 'web',
                'level' => $request->integer('level'),
                'description' => $request->input('description'),
            ]);

            $role->syncPermissions($requested->all());

            // `is_assignable` has a DB-level default, and MySQL returns no
            // defaults into the in-memory model after an INSERT — without the
            // refresh the response would report it as false while the row says
            // true. Same trap as every other store() in this codebase.
            return $role->refresh();
        });

        $this->flushCaches();

        return response()->json([
            'data' => $this->present($role->load('permissions'), RoleHierarchy::assignableBy($actor)),
        ], 201);
    }

    #[OA\Get(
        path: '/roles/{role}',
        summary: 'View a role',
        tags: ['Roles & Permissions'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Role')]
    )]
    public function show(Request $request, Role $role): JsonResponse
    {
        return response()->json([
            'data' => $this->present($role->load('permissions'), RoleHierarchy::assignableBy($request->user())),
        ]);
    }

    #[OA\Put(
        path: '/roles/{role}',
        summary: 'Update a role\'s rank, description or permissions',
        description: 'The name cannot change — route middleware refers to roles by name. Sending `permissions` replaces the set wholesale; omitting the key leaves it untouched.',
        tags: ['Roles & Permissions'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'level', type: 'integer'),
                new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Role updated'),
            new OA\Response(response: 403, description: 'Role ranks at or above the caller'),
            new OA\Response(response: 422, description: 'A permission the caller does not hold'),
        ]
    )]
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $actor = $request->user();
        $this->authoriseRankOver($actor, $role);

        if ($request->has('permissions')) {
            $requested = collect($request->input('permissions', []));

            if ($refused = $this->refusedPermissions($actor, $requested)) {
                return $this->cannotGrant($refused);
            }
        }

        DB::transaction(function () use ($request, $role) {
            $role->fill($request->safe()->only(['description', 'level', 'is_assignable']))->save();

            if ($request->has('permissions')) {
                $role->syncPermissions($request->input('permissions', []));
            }
        });

        $this->flushCaches();

        return response()->json([
            'data' => $this->present($role->refresh()->load('permissions'), RoleHierarchy::assignableBy($actor)),
        ]);
    }

    #[OA\Delete(
        path: '/roles/{role}',
        summary: 'Delete a role',
        description: 'Refused while any account still holds it — reassign those users first. Refused too if the role ranks at or above the caller, which is what stops anyone deleting their own role out from under themselves.',
        tags: ['Roles & Permissions'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Role deleted'),
            new OA\Response(response: 403, description: 'Role ranks at or above the caller'),
            new OA\Response(response: 409, description: 'Role is still assigned to accounts'),
        ]
    )]
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authoriseRankOver($request->user(), $role);

        $holders = $role->users()->count();

        if ($holders > 0) {
            return response()->json([
                'message' => "This role is still assigned to {$holders} account(s). Move them to another role first.",
                'errors' => ['role' => ['Role is in use.']],
            ], 409);
        }

        $role->delete();
        $this->flushCaches();

        return response()->json(['message' => 'Role deleted.']);
    }

    #[OA\Get(
        path: '/permissions',
        summary: 'The permission taxonomy, grouped by module',
        description: 'Everything a permission-matrix UI needs: every module and action that exists, plus the subset the caller may grant. Names are `{module}.{action}` and match what the route middleware checks.',
        tags: ['Roles & Permissions'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Permission catalogue')]
    )]
    public function permissions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => UserResource::permissionCatalogue(),
            'meta' => [
                // Anything outside this list is refused on write, so a UI can
                // grey those boxes out instead of failing the save.
                'grantable_by_you' => UserDirectory::grantablePermissions($request->user()),
                'total' => Permission::count(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/permissions',
        summary: 'Add a permission to the taxonomy',
        description: 'For a module that did not exist when the project was seeded. Creating the name is only half the job — a route still has to guard on it with `permission:{name}` before it means anything.',
        tags: ['Roles & Permissions'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name'],
            properties: [new OA\Property(property: 'name', type: 'string', example: 'housekeeping.inspect')]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Permission created and granted to the caller\'s own role'),
            new OA\Response(response: 422, description: 'Malformed or duplicate name'),
        ]
    )]
    public function storePermission(StorePermissionRequest $request): JsonResponse
    {
        $permission = DB::transaction(function () use ($request) {
            $permission = Permission::create([
                'name' => $request->string('name')->toString(),
                'guard_name' => 'web',
            ]);

            // Granted to the creator's own roles straight away. Otherwise the
            // person who just added it could not hand it to anybody, since
            // you may only grant what you hold.
            foreach ($request->user()->roles as $role) {
                $role->givePermissionTo($permission);
            }

            return $permission;
        });

        $this->flushCaches();

        return response()->json(['data' => ['name' => $permission->name]], 201);
    }

    #[OA\Delete(
        path: '/permissions/{permission}',
        summary: 'Remove a permission from the taxonomy',
        description: 'Refused while any role or account still holds it — clear it from those first, so nobody silently loses access mid-session.',
        tags: ['Roles & Permissions'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'permission', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Permission deleted'),
            new OA\Response(response: 409, description: 'Still held by a role or an account'),
        ]
    )]
    public function destroyPermission(Permission $permission): JsonResponse
    {
        $roles = $permission->roles()->count();
        $users = $permission->users()->count();

        if ($roles > 0 || $users > 0) {
            return response()->json([
                'message' => "Still held by {$roles} role(s) and {$users} account(s). Remove it from those first.",
                'errors' => ['permission' => ['Permission is in use.']],
            ], 409);
        }

        $permission->delete();
        $this->flushCaches();

        return response()->json(['message' => 'Permission deleted.']);
    }

    #[OA\Get(
        path: '/me/abilities',
        summary: 'What the signed-in user may see and do',
        description: 'Their roles, the flat permission list, and the same set as a `{module: {action: true}}` map. Intended for deciding which menus and buttons to render — the server enforces the same rules regardless of what the client chooses to show.',
        tags: ['Roles & Permissions'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Effective abilities')]
    )]
    public function abilities(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->load('roles', 'permissions');

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'level' => RoleHierarchy::levelOf($user),
                'can_manage_users' => $user->can('users.view'),
                'can_manage_roles' => $user->can('roles.manage'),
                'assignable_roles' => RoleHierarchy::assignableBy($user),
            ],
        ]);
    }

    /** @param  array<int, string>  $assignable */
    private function present(Role $role, array $assignable, ?int $holders = null): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'level' => (int) $role->level,
            'is_assignable' => (bool) $role->is_assignable,
            'users_count' => $holders ?? $role->users()->count(),
            'assignable_by_you' => in_array($role->name, $assignable, true),
            'permissions' => $role->permissions->pluck('name')->sort()->values(),
        ];
    }

    /**
     * Holder counts straight off the pivot.
     *
     * `Role::withCount('users')` looks like the obvious way to do this, but
     * spatie resolves the `users` relation through the row's `guard_name`,
     * and the instance Eloquent builds for a withCount subquery has no
     * attributes — so it blows up with "Class name must be a valid object or
     * a string". One grouped query avoids both that and an N+1.
     *
     * @return array<int, int>
     */
    private function holderCounts(): array
    {
        // spatie ships this config key set to null and falls back internally,
        // so `config(..., 'role_id')` would not help — the key exists, it is
        // just empty. Coalesce on the value instead.
        $roleKey = config('permission.column_names.role_pivot_key') ?: 'role_id';

        return DB::table(config('permission.table_names.model_has_roles'))
            ->select($roleKey, DB::raw('count(*) as holders'))
            ->groupBy($roleKey)
            ->pluck('holders', $roleKey)
            ->all();
    }

    private function authoriseRankOver(User $actor, Role $role): void
    {
        abort_unless(
            RoleHierarchy::levelOf($actor) < (int) $role->level,
            403,
            'You can only manage roles that rank below your own.'
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $requested
     * @return array<int, string>
     */
    private function refusedPermissions(User $actor, $requested): array
    {
        return $requested->diff(UserDirectory::grantablePermissions($actor))->values()->all();
    }

    /** @param  array<int, string>  $refused */
    private function cannotGrant(array $refused): JsonResponse
    {
        return response()->json([
            'message' => 'You cannot grant permissions you do not hold yourself.',
            'errors' => ['permissions' => $refused],
        ], 422);
    }

    /**
     * Both caches are per-process: spatie memoises the permission map, and
     * RoleHierarchy memoises levels. Skipping either leaves the rest of the
     * request deciding on stale authority.
     */
    private function flushCaches(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        RoleHierarchy::forget();
    }
}
