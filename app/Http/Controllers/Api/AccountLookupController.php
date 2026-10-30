<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/accounts/{account_number}
 *
 * "Who owns this account number?" The app calls this while the sender is
 * typing the recipient's number, then shows "Send to Bola Adeyemi?" so the
 * sender can stop if they mistyped and the name is wrong.
 *
 * Accepts the account number OR the phone number in any format.
 * Returns ONLY the name (never email, phone, balance or IDs), and is
 * rate-limited in routes/api.php so nobody can scan through every number.
 */
class AccountLookupController extends Controller
{
    public function show(string $accountNumber): JsonResponse
    {
        $wallet = Wallet::with('user')
            ->where('wallet_number', PhoneNumber::accountNumberFromInput($accountNumber))
            ->first();

        if ($wallet === null) {
            return response()->json(['message' => 'No account was found with this account number.'], 404);
        }

        return response()->json([
            'data' => [
                'account_number' => $wallet->wallet_number,
                'account_name' => $wallet->user->name,
            ],
        ]);
    }
}
