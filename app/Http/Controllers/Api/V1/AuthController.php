<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * @tags Authentication & Profile
 */
class AuthController extends Controller
{
    /**
     * Authenticate user and issue API bearer token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $login = trim($request->input('login'));
        $password = $request->input('password');
        $deviceName = $request->input('device_name', 'Api Device');

        $user = User::where('username', $login)
            ->orWhere('email', $login)
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Kredensial yang diberikan tidak cocok dengan data kami.',
                'errors' => [
                    'login' => ['Username, email, atau kata sandi salah.'],
                ],
            ], 422);
        }

        $user->load(['role', 'area', 'divisi', 'position', 'approval']);
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => new UserResource($user),
            ],
        ]);
    }

    /**
     * Revoke current access token (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil. Token telah dihapus.',
        ]);
    }

    /**
     * Get authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'area', 'divisi', 'position', 'approval']);

        return response()->json([
            'success' => true,
            'message' => 'Data profil berhasil diambil.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Update user profile (email and phone).
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->only(['email', 'no_hp']));
        $user->load(['role', 'area', 'divisi', 'position', 'approval']);

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Change user password.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => [
                    'current_password' => ['Kata sandi saat ini tidak cocok.'],
                ],
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->input('new_password')),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kata sandi berhasil diperbarui.',
        ]);
    }

    /**
     * Get direct subordinates of the authenticated user.
     */
    public function subordinates(Request $request): JsonResponse
    {
        $user = $request->user();
        $subordinates = User::where('approval_id', $user->id)
            ->with(['role', 'area', 'divisi', 'position'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar bawahan berhasil diambil.',
            'data' => UserResource::collection($subordinates),
            'meta' => [
                'total' => $subordinates->count(),
            ],
        ]);
    }
}
