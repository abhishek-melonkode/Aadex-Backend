<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Models\LoginActivityLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\Identity\SessionResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

/**
 * Session management for the signed-in user. A "session" here is one Sanctum
 * personal access token — one login on one device. Everything is scoped to
 * $request->user()'s own tokens: there is deliberately no way to read or
 * revoke another user's sessions from this controller (that is the Super
 * Admin's change-password action, which revokes the target's tokens).
 */
class SessionController extends Controller
{
    #[OA\Get(
        path: '/auth/sessions',
        summary: 'List the current user\'s active sessions (devices)',
        description: 'One row per live Sanctum token, with the IP and user agent captured at login. Expired tokens are filtered out even before `sanctum:prune-expired` deletes them.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Active sessions')]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $user->currentAccessToken()->id;

        $tokens = $user->tokens()->latest('id')->get()->filter(
            fn (PersonalAccessToken $token) => $this->isLive($token)
        );

        $activity = $this->activityFor($tokens->pluck('id')->all());

        return response()->json([
            'data' => $tokens->map(fn (PersonalAccessToken $token) => new SessionResource(
                $token,
                $activity->get($token->id),
                $token->id === $currentId,
            ))->values(),
        ]);
    }

    #[OA\Delete(
        path: '/auth/sessions/{session}',
        summary: 'Revoke one of the current user\'s sessions',
        description: 'Signs that device out. Revoking the session you are calling from is allowed and is equivalent to logging out — the response says so via `revoked_current`.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'session', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Session revoked'),
            new OA\Response(response: 404, description: 'No such session for this user'),
        ]
    )]
    public function destroy(Request $request, int $session): JsonResponse
    {
        $user = $request->user();
        $token = $user->tokens()->whereKey($session)->first();

        if ($token === null) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $wasCurrent = $token->id === $user->currentAccessToken()->id;

        LoginActivityLog::closeForTokens($token->id);
        $token->delete();

        return response()->json([
            'message' => 'Session revoked.',
            'revoked_current' => $wasCurrent,
        ]);
    }

    #[OA\Post(
        path: '/auth/logout-all',
        summary: 'Sign out of every device',
        description: 'Revokes every token for the current user, including the one making the call — the client must log in again afterwards.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'All sessions revoked')]
    )]
    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $tokenIds = $user->tokens()->pluck('id')->all();

        LoginActivityLog::closeForTokens($tokenIds);
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Signed out of all devices.',
            'revoked_count' => count($tokenIds),
        ]);
    }

    /**
     * Mirrors Sanctum\Guard::isValidAccessToken — a token is live only if it
     * is inside the global expiration window AND its own expires_at (if any)
     * has not passed. Listing a token the guard would already reject would be
     * lying to the user about what is signed in.
     */
    private function isLive(PersonalAccessToken $token): bool
    {
        $window = config('sanctum.expiration');

        if ($window && $token->created_at?->lte(now()->subMinutes((int) $window))) {
            return false;
        }

        return ! $token->expires_at || $token->expires_at->isFuture();
    }

    /**
     * @param  array<int, int>  $tokenIds
     * @return Collection<int, LoginActivityLog>
     */
    private function activityFor(array $tokenIds): Collection
    {
        return LoginActivityLog::whereIn('personal_access_token_id', $tokenIds)
            ->latest('id')
            ->get()
            ->keyBy('personal_access_token_id');
    }
}
