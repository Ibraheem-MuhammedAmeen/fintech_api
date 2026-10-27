<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\Wallet;
use Illuminate\Support\Str;

class AuthService
{
    public function register(array $data): array
    {
        $user = DB::transaction(function () use ($data) {

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            do {
                $walletNumber = '9' . Str::random(9);
            } while (
                Wallet::where('wallet_number', $walletNumber)->exists()
            );

            $user->wallet()->create([
                'wallet_number' => $walletNumber,
                'currency' => 'NGN',
                'status' => 'active',
            ]);

            return $user;
        });

        $token = $user->createToken('flutter-app')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    public function login(array $data): array
    {
        $user = User::where('email', $data['email'])->first();

        if (
            !$user ||
            !Hash::check($data['password'], $user->password)
        ) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        $token = $user->createToken('flutter-app')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }
    }
}