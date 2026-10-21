<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Cookie-based Sanctum SPA authentication. The SPA must first call
 * GET /sanctum/csrf-cookie, then send X-XSRF-TOKEN on state-changing requests.
 */
class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        $user = User::where('email', $credentials['email'])->first();

        if (! Auth::guard('web')->attempt([...$credentials, 'is_active' => true], $request->boolean('remember'))) {
            $this->audit->log('auth.login', $user, ['email' => $credentials['email'], 'reason' => $user && ! $user->is_active ? 'inactive' : 'invalid_credentials'], 'failure', $user?->id);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();
        $user = Auth::guard('web')->user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->audit->log('auth.login', $user, [], 'success', $user->id);

        return response()->json(['user' => new UserResource($user->load('roles'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->log('auth.logout', $request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);
        $status = Password::sendResetLink($request->only('email'));
        $this->audit->log('auth.password_reset_requested', null, ['email' => $request->input('email')], $status === Password::RESET_LINK_SENT ? 'success' : 'failure');

        // Generic response prevents account enumeration.
        return response()->json(['message' => 'If that email is registered, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            app(\App\Services\UserAdminService::class)->revokeSessions($user);
            $this->audit->log('auth.password_reset', $user, [], 'success', $user->id);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => __($status)]);
    }

    public function updateProfile(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);
        $user->update($data);
        $this->audit->log('profile.updated', $user, ['fields' => array_keys($user->getChanges())]);

        return new UserResource($user->load('roles'));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);
        $user = $request->user();
        // Rotating remember_token invalidates "remember me" cookies on other devices.
        $user->forceFill(['password' => $request->input('password'), 'remember_token' => \Illuminate\Support\Str::random(60)])->save();
        // Revoke every other session; keep the current one alive.
        if (config('session.driver') === 'database') {
            \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }
        $request->session()->put(['password_hash_web' => $user->getAuthPassword()]);
        $request->session()->regenerate();
        $this->audit->log('auth.password_changed', $user);

        return response()->json(['message' => 'Password updated. Other sessions have been signed out.']);
    }
}
