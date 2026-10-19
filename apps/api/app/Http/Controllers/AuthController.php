<?php

namespace App\Http\Controllers;

use App\Models\Pengguna;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $pengguna = Pengguna::where('email', $request->email)->first();

        if (!$pengguna || !Hash::check($request->password, $pengguna->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Email atau password salah.',
            ], 401);
        }

        Auth::guard()->setRememberDuration(60 * 24 * 30);
        Auth::login($pengguna, $request->boolean('remember', false));
        $request->session()->regenerate();

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'data' => [
                'user_id' => $pengguna->user_id, 
                'nama' => $pengguna->nama,
                'email' => $pengguna->email,
                'role' => $pengguna->role,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $pengguna = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $pengguna->user_id, 
                'nama' => $pengguna->nama,
                'email' => $pengguna->email,
                'role' => $pengguna->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil.',
        ]);
    }
}
