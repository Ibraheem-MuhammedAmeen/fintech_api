<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DemoFundingRequest;
use App\Services\DemoFundingService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/wallet/fund-demo
 *
 * The controller stays thin: validation happens in DemoFundingRequest,
 * the money logic happens in DemoFundingService. This method only
 * connects the two and shapes the JSON response.
 */
class DemoFundingController extends Controller
{
    public function __construct(
        protected DemoFundingService $demoFundingService
    ) {}

    public function fund(DemoFundingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->demoFundingService->fund(
            $request->user(),                 // wallet comes from the token, never from the body
            (int) $validated['amount_kobo'],
            $validated['idempotency_key'],
        );

        $funding = $result['funding'];

        return response()->json([
            'message' => $result['replayed']
                ? 'This request was already processed. No new funds were added.'
                : 'Demo funding successful.',
            'data' => [
                'reference' => $funding->reference,
                'funding_request_id' => $funding->id,
                'wallet_id' => $funding->wallet_id,
                'amount_kobo' => $funding->amount_kobo,
                'balance_kobo' => $result['balance_kobo'],
                'idempotency_key' => $funding->idempotency_key,
                'idempotent_replay' => $result['replayed'],
                'created_at' => $funding->created_at,
            ],
        ], $result['replayed'] ? 200 : 201);
    }
}