<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransferRequest;
use App\Http\Resources\TransferResource;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * POST /api/v1/transfers         send money to another wallet
 * GET  /api/v1/transfers         list my transfers (sent and received)
 * GET  /api/v1/transfers/{id}    one transfer's details
 *
 * Thin controller: validation lives in TransferRequest,
 * money logic lives in TransferService.
 */
class TransferController extends Controller
{
    public function __construct(
        protected TransferService $transferService
    ) {}

    public function store(TransferRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->transferService->send(
            $request->user(),                       // sender = whoever owns the token
            $validated['recipient_wallet_number'],
            (int) $validated['amount_kobo'],
            $validated['narration'] ?? null,
            $validated['idempotency_key'],
        );

        $transfer = $result['transfer']->load(['senderWallet.user', 'recipientWallet.user']);

        return (new TransferResource($transfer))
            ->additional([
                'message' => $result['replayed']
                    ? 'This transfer was already processed. No new money was sent.'
                    : 'Transfer successful.',
                'meta' => [
                    'balance_kobo' => $result['balance_kobo'],
                    'idempotent_replay' => $result['replayed'],
                ],
            ])
            ->response()
            ->setStatusCode($result['replayed'] ? 200 : 201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'direction' => ['sometimes', 'in:sent,received'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $transfers = $this->transferService->listForUser(
            $request->user(),
            $validated['direction'] ?? null,
            (int) ($validated['per_page'] ?? 15),
        );

        return TransferResource::collection($transfers);
    }

    public function show(Request $request, int $transfer): TransferResource
    {
        return new TransferResource(
            $this->transferService->findForUser($request->user(), $transfer)
        );
    }
}