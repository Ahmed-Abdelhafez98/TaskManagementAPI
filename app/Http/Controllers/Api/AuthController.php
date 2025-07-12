<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends BaseApiController
{
    /**
     * Rate limiting key prefix for login attempts
     */
    private const LOGIN_RATE_LIMIT_KEY = 'login_attempt';

    /**
     * Maximum login attempts per minute
     */
    private const MAX_LOGIN_ATTEMPTS = 5;

    /**
     * Login user and return access token
     *
     * @param LoginRequest $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            // Check rate limiting
            $rateLimitKey = self::LOGIN_RATE_LIMIT_KEY . ':' . $request->ip();

            if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_LOGIN_ATTEMPTS)) {
                $seconds = RateLimiter::availableIn($rateLimitKey);
                Log::warning('Too many login attempts', [
                    'ip' => $request->ip(),
                    'email' => $request->email,
                    'retry_after' => $seconds
                ]);

                return $this->errorResponse(
                    'Too many login attempts. Please try again in ' . $seconds . ' seconds.',
                    429
                );
            }

            $user = $this->findUserByEmail($request->email);

            if (!$user || !$this->verifyPassword($request->password, $user->password)) {
                RateLimiter::hit($rateLimitKey, 60); // 1 minute decay

                Log::warning('Failed login attempt', [
                    'ip' => $request->ip(),
                    'email' => $request->email,
                    'user_exists' => $user !== null
                ]);

                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }

            // Clear rate limit on successful login
            RateLimiter::clear($rateLimitKey);

            $token = $this->generateUserToken($user);

            Log::info('User logged in successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip()
            ]);

            return $this->successResponse([
                'user' => new UserResource($user),
                'token' => $token,
            ], 'Login successful');
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Login failed due to system error', [
                'error' => $e->getMessage(),
                'email' => $request->email ?? 'unknown',
                'ip' => $request->ip()
            ]);
            return $this->serverErrorResponse('Login failed. Please try again.');
        }
    }

    /**
     * Register a new user (for development/seeding purposes)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function register(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:manager,user',
        ]);

        try {
            $user = DB::transaction(function () use ($validatedData) {
                return User::create([
                    'name' => $validatedData['name'],
                    'email' => $validatedData['email'],
                    'password' => Hash::make($validatedData['password']),
                    'role' => $validatedData['role'],
                ]);
            });

            $token = $this->generateUserToken($user);

            Log::info('User registered successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'ip' => $request->ip()
            ]);

            return $this->createdResponse([
                'user' => new UserResource($user),
                'token' => $token,
            ], 'User registered successfully');
        } catch (Throwable $e) {
            Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'email' => $validatedData['email'] ?? 'unknown',
                'ip' => $request->ip()
            ]);
            return $this->serverErrorResponse('Registration failed. Please try again.');
        }
    }

    /**
     * Get authenticated user profile
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            return $this->successResponse([
                'user' => new UserResource($user)
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to retrieve user profile', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);
            return $this->serverErrorResponse('Failed to retrieve user profile.');
        }
    }

    /**
     * Logout user and revoke current token
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if ($user && $request->user()->currentAccessToken()) {
                $request->user()->currentAccessToken()->delete();

                Log::info('User logged out successfully', [
                    'user_id' => $user->id,
                    'ip' => $request->ip()
                ]);
            }

            return $this->successResponse(null, 'Logged out successfully');
        } catch (Throwable $e) {
            Log::error('Logout failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);
            return $this->serverErrorResponse('Logout failed. Please try again.');
        }
    }

    /**
     * Logout user from all devices
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logoutAll(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $tokensRevoked = 0;

            if ($user) {
                $tokensRevoked = $user->tokens()->count();
                $user->tokens()->delete();

                Log::info('User logged out from all devices', [
                    'user_id' => $user->id,
                    'tokens_revoked' => $tokensRevoked,
                    'ip' => $request->ip()
                ]);
            }

            return $this->successResponse([
                'tokens_revoked' => $tokensRevoked
            ], 'Logged out from all devices successfully');
        } catch (Throwable $e) {
            Log::error('Logout from all devices failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);
            return $this->serverErrorResponse('Logout failed. Please try again.');
        }
    }

    /**
     * Find user by email
     *
     * @param string $email
     * @return User|null
     */
    private function findUserByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    /**
     * Verify password against hash
     *
     * @param string $password
     * @param string $hash
     * @return bool
     */
    private function verifyPassword(string $password, string $hash): bool
    {
        return Hash::check($password, $hash);
    }

    /**
     * Generate access token for user
     *
     * @param User $user
     * @return string
     */
    private function generateUserToken(User $user): string
    {
        return $user->createToken('api-token')->plainTextToken;
    }
}
