<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Models\LoginActivityLog;
use App\Domain\Identity\Models\PasswordResetOtp;
use App\Domain\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\Identity\SessionResource;
use App\Http\Resources\Identity\UserResource;
use App\Http\Resources\Tenancy\HotelResource;
use App\Models\User;
use App\Notifications\PasswordResetOtpNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/auth/register',
        summary: 'Register a new hotel account (awaits Super Admin approval)',
        description: 'Public self-signup. Creates the hotel and its Hotel Admin user, both with status `pending` — no token is issued, and `POST /auth/login` answers 403 until a Super Admin approves the account. (The approval endpoint lives in the Super Admin module, which is not published on this page yet.)',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['hotel_name', 'admin_name', 'email', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'hotel_name', type: 'string'),
                new OA\Property(property: 'admin_name', type: 'string'),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'mobile', type: 'string', nullable: true),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
                new OA\Property(property: 'phone', type: 'string', nullable: true),
                new OA\Property(property: 'address', type: 'string', nullable: true),
                new OA\Property(property: 'city', type: 'string', nullable: true),
                new OA\Property(property: 'state', type: 'string', nullable: true),
                new OA\Property(property: 'country', type: 'string', nullable: true),
                new OA\Property(property: 'gst_tax_id', type: 'string', nullable: true),
                new OA\Property(property: 'currency', type: 'string', nullable: true, example: 'INR'),
                new OA\Property(property: 'timezone', type: 'string', nullable: true, example: 'Asia/Kolkata'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Registered, pending approval'),
            new OA\Response(response: 422, description: 'Validation failed'),
            new OA\Response(response: 429, description: 'Too many signup attempts'),
        ]
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        [$hotel, $user] = DB::transaction(function () use ($request) {
            $hotel = Hotel::create([
                ...$request->safe()->except(['hotel_name', 'admin_name', 'email', 'mobile', 'password']),
                'name' => $request->string('hotel_name'),
                'admin_name' => $request->string('admin_name'),
                'admin_email' => $request->string('email'),
                'status' => 'pending',
                'registered_at' => now(),
            ]);

            $user = User::create([
                'name' => $request->string('admin_name'),
                'email' => $request->string('email'),
                'mobile' => $request->input('mobile'),
                'password' => Hash::make($request->string('password')),
                'hotel_id' => $hotel->id,
                'status' => 'pending',
            ]);

            $user->assignRole('hotel_admin');

            return [$hotel->refresh(), $user->refresh()];
        });

        return response()->json([
            'message' => 'Registration received. Your account is pending approval — you will be able to log in once a Super Admin activates it.',
            'hotel' => new HotelResource($hotel),
            'user' => new UserResource($user),
        ], 201);
    }

    #[OA\Post(
        path: '/auth/login',
        summary: 'Log in and receive a Sanctum API token',
        description: 'Returns `{ token, expires_at, user }`. `expires_at` is when the token dies (from `sanctum.expiration`, default 7 days) — clients should re-login before then. `device_name` labels the session in `GET /auth/sessions`.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
                new OA\Property(property: 'device_name', type: 'string', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Authenticated'),
            new OA\Response(response: 401, description: 'Invalid credentials'),
            new OA\Response(response: 403, description: 'Account inactive or pending approval'),
        ]
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($user->status === 'pending') {
            return response()->json(['message' => 'This account is pending approval by a Super Admin.'], 403);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'This account is inactive.'], 403);
        }

        $token = $user->createToken($request->string('device_name')->toString() ?: 'api');

        $user->forceFill(['last_login_at' => now()])->save();

        LoginActivityLog::create([
            'user_id' => $user->id,
            'personal_access_token_id' => $token->accessToken->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'logged_in_at' => now(),
        ]);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => SessionResource::effectiveExpiry($token->accessToken)?->toIso8601String(),
            'user' => new UserResource($user),
        ]);
    }

    #[OA\Post(
        path: '/auth/logout',
        summary: 'Revoke the current API token',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Logged out')]
    )]
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Close the log row for *this* token, not merely the newest open one —
        // a user signed in on several devices has several open rows.
        LoginActivityLog::closeForTokens($token->id);

        $token->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    #[OA\Get(
        path: '/auth/me',
        summary: 'Get the authenticated user',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Current user')]
    )]
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    #[OA\Post(
        path: '/auth/change-password',
        summary: "Change the authenticated user's own password",
        description: 'Verifies the current password, then revokes every *other* token for this user so other devices are signed out. The token used for this call stays valid.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Password changed'),
            new OA\Response(response: 422, description: 'Current password incorrect, or new password invalid'),
        ]
    )]
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->string('current_password'), $user->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors' => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        $currentTokenId = $user->currentAccessToken()->id;

        $user->forceFill(['password' => Hash::make($request->string('password'))])->save();

        // Sign out every other device, but keep the caller signed in — a
        // routine password change shouldn't force an immediate re-login.
        // Close their audit rows first: the FK is nulled on delete, so after
        // the delete these rows can never be matched to a token again and
        // would sit open forever, reporting devices as still signed in.
        $revoked = $user->tokens()->whereKeyNot($currentTokenId)->pluck('id')->all();
        LoginActivityLog::closeForTokens($revoked);
        $user->tokens()->whereKeyNot($currentTokenId)->delete();

        return response()->json(['message' => 'Password changed. Other devices have been signed out.']);
    }

    #[OA\Post(
        path: '/auth/forgot-password',
        summary: 'Request a password reset OTP by email',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [new OA\Property(property: 'email', type: 'string', format: 'email')]
        )),
        responses: [new OA\Response(response: 200, description: 'OTP sent if the account exists')]
    )]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();
        $user = User::where('email', $email)->first();

        if ($user) {
            $otp = (string) random_int(100000, 999999);

            DB::transaction(function () use ($email, $otp) {
                // Burn any code still outstanding for this address. Without
                // this, every request added another simultaneously-valid code,
                // so N requests meant N chances for a guess to land — and an
                // old code kept working even after a successful reset.
                PasswordResetOtp::where('email', $email)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);

                PasswordResetOtp::create([
                    'email' => $email,
                    'otp' => $otp,
                    'expires_at' => now()->addMinutes(10),
                ]);
            });

            $user->notify(new PasswordResetOtpNotification($otp));
        }

        // Always return a generic message regardless of whether the email
        // exists, so this endpoint can't be used to enumerate accounts.
        return response()->json(['message' => 'If that account exists, a reset code has been sent.']);
    }

    #[OA\Post(
        path: '/auth/reset-password',
        summary: 'Reset password using an OTP',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'otp', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'otp', type: 'string'),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Password reset'),
            new OA\Response(response: 422, description: 'Invalid or expired OTP'),
        ]
    )]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();

        $otpRecord = PasswordResetOtp::where('email', $email)
            ->where('otp', $request->string('otp'))
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otpRecord || ! $otpRecord->isValid()) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user = User::where('email', $email)->firstOrFail();

        DB::transaction(function () use ($user, $email, $request) {
            $user->forceFill(['password' => Hash::make($request->string('password'))])->save();

            // Consume every outstanding code for this address, not just the
            // one that was used — a reset must not leave a spare key behind.
            PasswordResetOtp::where('email', $email)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            // Close the audit rows before deleting the tokens: the FK is
            // nulled on delete, so afterwards these rows can never be matched
            // and would stay "still signed in" forever.
            LoginActivityLog::closeForTokens($user->tokens()->pluck('id')->all());
            $user->tokens()->delete();
        });

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
