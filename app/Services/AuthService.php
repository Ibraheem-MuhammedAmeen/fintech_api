<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Support\PhoneNumber;

class AuthService
{
    public function register(array $data): array
    {
        $user = DB::transaction(function () use ($data) {

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],   // already normalised: "08031234567"
                'password' => Hash::make($data['password']),
            ]);

            // OPay style: the account number IS the phone number without
            // the leading 0. "08031234567" -> "8031234567".
            // RegisterRequest already checked that it is not taken; the
            // unique index on wallet_number is the final safety net.
            $user->wallet()->create([
                'wallet_number' => PhoneNumber::toAccountNumber($data['phone']),
                'currency' => 'NGN',
                'status' => 'active',
            ]);

            return $user;
        });

        $token = $user->createToken('flutter-app')->plainTextToken;

        return [
            'user' => $user,
            'account_number' => $user->wallet->wallet_number,
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