<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        // Laravel 5.6's TokenGuard has no "hash" option (it was added in a
        // later release), so the token is necessarily stored and compared
        // in plaintext via config/auth.php's token-driver guard.
        $token = Str::random(64);
        $user->api_token = $token;
        $user->save();

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
        ])->header('Cache-Control', 'no-store');
    }
}
