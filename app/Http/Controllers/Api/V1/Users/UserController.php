<?php

namespace App\Http\Controllers\Api\V1\Users;

use App\Domain\Identity\Models\LoginActivityLog;
use App\Domain\Identity\Support\RoleHierarchy;
use App\Domain\Identity\Support\UserDirectory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\SyncUserPermissionsRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\Identity\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

/**
 * Staff/user management and the per-user permission matrix.
 *
 * Two independent gates guard every write, and both are needed:
 *
 *  - the `permission:users.*` route middleware answers "may this actor manage
 *    users at all";
 *  - UserDirectory + RoleHierarchy answer "may they manage *this* user" —
 *    same tenant, strictly lower rank, never themselves.
 *
 * On top of that an actor can only ever grant permissions they already hold,
 * so nobody can widen their own reach through the accounts they create. Every
 * one of those rules has a test in UserManagementTest; treat a failure there
 * as a privilege-escalation report, not a broken assertion.
 */
class UserController extends Controller
{
    #[OA\Get(
        path: '/users',
        summary: 'List the users the caller is allowed to manage',
        description: 'Super Admin sees everyone; a Chain Admin sees accounts in their chain; a Hotel Admin sees their own hotel. Filter with `?role=`, `?status=` or `?search=`.',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'pending'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Users in scope')]
    )]
    public function index(Request $request): JsonResponse
    {
        $users = UserDirectory::query($request->user())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->whereHas(
                'roles',
                fn ($r) => $r->where('name', $request->string('role'))
            ))
            ->with('roles', 'permissions')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => UserResource::listWithSources($users),
            'meta' => [
                'assignable_roles' => RoleHierarchy::assignableBy($request->user()),
                'grantable_permissions' => UserDirectory::grantablePermissions($request->user()),
            ],
        ]);
    }

    #[OA\Post(
        path: '/users',
        summary: 'Create a user',
        description: 'The role must be below the caller\'s own. Anyone who is not a Super Admin creates inside their own hotel — a `hotel_id` in the body is ignored for them.',
        tags: ['Users'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name', 'email', 'password', 'password_confirmation', 'role'],
            properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'mobile', type: 'string', nullable: true),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
                new OA\Property(property: 'role', type: 'string', example: 'staff'),
                new OA\Property(property: 'hotel_id', type: 'integer', nullable: true),
                new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'User created'),
            new OA\Response(response: 422, description: 'Validation failed, or a permission the caller does not hold'),
        ]
    )]
    public function store(StoreUserRequest $request): JsonResponse
    {
        $actor = $request->user();
        $requested = collect($request->input('permissions', []));

        if ($refused = $this->refusedPermissions($actor, $requested)) {
            return $this->cannotGrant($refused);
        }

        $tenancy = UserDirectory::tenancyForNewUser($actor, $request->integer('hotel_id') ?: null);

        $user = DB::transaction(function () use ($request, $requested, $tenancy) {
            $user = User::create([
                'name' => $request->string('name'),
                'email' => $request->string('email'),
                'mobile' => $request->input('mobile'),
                'password' => Hash::make($request->string('password')),
                'hotel_id' => $tenancy['hotel_id'],
                'chain_id' => $tenancy['chain_id'],
                'status' => 'active',
            ]);

            $user->syncRoles([$request->string('role')->toString()]);

            if ($requested->isNotEmpty()) {
                $user->syncPermissions($requested->all());
            }

            return $user->refresh();
        });

        return response()->json(['data' => (new UserResource($user->load('roles', 'permissions')))->withSources()], 201);
    }

    #[OA\Get(
        path: '/users/{user}',
        summary: 'View a user in scope',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'User'),
            new OA\Response(response: 404, description: 'Not in the caller\'s scope'),
        ]
    )]
    public function show(Request $request, User $user): JsonResponse
    {
        // 404 rather than 403: an out-of-scope id must not be confirmed to
        // exist. Same reasoning as the session endpoints.
        abort_unless(UserDirectory::isVisibleTo($request->user(), $user), 404);

        return response()->json(['data' => (new UserResource($user->load('roles', 'permissions')))->withSources()]);
    }

    #[OA\Put(
        path: '/users/{user}',
        summary: 'Update a user\'s profile, status or role',
        description: 'Deactivating (`status: inactive`) also revokes every token that user holds, so the change takes effect immediately rather than at the next token expiry.',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'mobile', type: 'string', nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
                new OA\Property(property: 'role', type: 'string', example: 'staff'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'User updated'),
            new OA\Response(response: 403, description: 'Caller does not outrank this user, or it is their own account'),
            new OA\Response(response: 404, description: 'Not in the caller\'s scope'),
        ]
    )]
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authoriseManagement($request->user(), $user);

        DB::transaction(function () use ($request, $user) {
            $user->fill($request->safe()->only(['name', 'mobile', 'status']))->save();

            if ($request->filled('role')) {
                $user->syncRoles([$request->string('role')->toString()]);
            }

            if ($request->input('status') === 'inactive') {
                $this->revokeAllTokens($user);
            }
        });

        return response()->json(['data' => (new UserResource($user->refresh()->load('roles', 'permissions')))->withSources()]);
    }

    #[OA\Delete(
        path: '/users/{user}',
        summary: 'Deactivate a user',
        description: 'Sets `status` to `inactive` and revokes every token, which blocks login immediately. The row itself is kept: `login_activity_logs` references it, and deleting it would take the audit trail with it. Reactivate with `PUT /users/{user}` and `status: active`.',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'User deactivated'),
            new OA\Response(response: 403, description: 'Caller does not outrank this user, or it is their own account'),
        ]
    )]
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authoriseManagement($request->user(), $user);

        DB::transaction(function () use ($user) {
            $user->forceFill(['status' => 'inactive'])->save();
            $this->revokeAllTokens($user);
        });

        return response()->json([
            'message' => 'User deactivated and signed out of every device.',
            'data' => (new UserResource($user->refresh()->load('roles', 'permissions')))->withSources(),
        ]);
    }

    #[OA\Put(
        path: '/users/{user}/permissions',
        summary: 'Replace a user\'s directly-granted permissions',
        description: 'Send the whole matrix, not a delta — an empty array clears every direct grant. Permissions inherited from the role are untouched and cannot be removed this way; change the role instead. A caller may only grant names they hold themselves.',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['permissions'],
            properties: [new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string', example: 'bookings.view'))]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Matrix replaced'),
            new OA\Response(response: 422, description: 'A permission the caller does not hold'),
        ]
    )]
    public function syncPermissions(SyncUserPermissionsRequest $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->authoriseManagement($actor, $user);

        $requested = collect($request->input('permissions', []));

        if ($refused = $this->refusedPermissions($actor, $requested)) {
            return $this->cannotGrant($refused);
        }

        $user->syncPermissions($requested->all());

        return response()->json(['data' => (new UserResource($user->refresh()->load('roles', 'permissions')))->withSources()]);
    }

    private function authoriseManagement(User $actor, User $target): void
    {
        // Existence first, so an out-of-scope id looks the same as a missing
        // one; only then the rank check, which may legitimately say 403.
        abort_unless(UserDirectory::isVisibleTo($actor, $target), 404);

        abort_if(
            $actor->is($target),
            403,
            'Manage your own account through /auth/change-password.'
        );

        abort_unless(
            RoleHierarchy::outranks($actor, $target),
            403,
            'You can only manage accounts with a role below your own.'
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $requested
     * @return array<int, string> names the actor may not hand out
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

    private function revokeAllTokens(User $user): void
    {
        // Close the audit rows before the tokens go: the FK nulls on delete,
        // so afterwards these rows can never be matched to a token again.
        LoginActivityLog::closeForTokens($user->tokens()->pluck('id')->all());
        $user->tokens()->delete();
    }
}
