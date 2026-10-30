<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs against the test database set in phpunit.xml (fintech_db_test),
 * never against fintech_db. Run with:  php artisan test --filter=TransferTest
 */
class TransferTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/transfers';

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function userWithWallet(int $balanceKobo = 0, array $walletAttributes = [], ?string $name = null): User
    {
        $user = User::factory()->create($name ? ['name' => $name] : []);

        $wallet = $user->wallet()->create(array_merge([
            'wallet_number' => '9' . Str::random(9),
            'currency' => 'NGN',
            'status' => 'active',
        ], $walletAttributes));

        if ($balanceKobo > 0) {
            $wallet->transactions()->create([
                'reference' => (string) Str::uuid(),
                'type' => 'credit',
                'amount_kobo' => $balanceKobo,
                'description' => 'Opening balance',
            ]);
        }

        return $user->fresh();
    }

    private function send(User $recipient, int|string|null $amount, ?string $key = 'tr-1', array $extraBody = [])
    {
        $headers = ['Accept' => 'application/json'];

        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $this->postJson(self::URL, array_merge([
            'recipient_wallet_number' => $recipient->wallet->wallet_number,
            'amount_kobo' => $amount,
        ], $extraBody), $headers);
    }

    private function balanceOf(User $user): int
    {
        $credits = (int) $user->wallet->transactions()->where('type', 'credit')->sum('amount_kobo');
        $debits = (int) $user->wallet->transactions()->where('type', 'debit')->sum('amount_kobo');

        return $credits - $debits;
    }

    private function assertNoTransferWritten(): void
    {
        $this->assertSame(0, Transfer::count());
        $this->assertSame(0, LedgerTransaction::count());
        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame(0, WalletTransaction::where('description', 'like', 'Transfer%')->count());
    }

    // ---------------------------------------------------------------
    // Access control
    // ---------------------------------------------------------------

    public function test_guests_are_rejected_on_every_transfer_route(): void
    {
        $this->postJson(self::URL, [])->assertUnauthorized();
        $this->getJson(self::URL)->assertUnauthorized();
        $this->getJson(self::URL . '/1')->assertUnauthorized();
    }

    // ---------------------------------------------------------------
    // POST /transfers — happy path
    // ---------------------------------------------------------------

    public function test_transfer_moves_money_and_writes_balanced_ledger(): void
    {
        $alice = $this->userWithWallet(1_000_000, name: 'Alice');
        $bob = $this->userWithWallet(0, name: 'Bob');
        Sanctum::actingAs($alice);

        $response = $this->send($bob, 300_000, 'tr-1', ['narration' => 'Lunch money'])
            ->assertCreated()
            ->assertJsonPath('message', 'Transfer successful.')
            ->assertJsonPath('data.direction', 'sent')
            ->assertJsonPath('data.amount_kobo', 300_000)
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.narration', 'Lunch money')
            ->assertJsonPath('data.status', 'successful')
            ->assertJsonPath('data.sender.wallet_number', $alice->wallet->wallet_number)
            ->assertJsonPath('data.sender.name', 'Alice')
            ->assertJsonPath('data.recipient.wallet_number', $bob->wallet->wallet_number)
            ->assertJsonPath('data.recipient.name', 'Bob')
            ->assertJsonPath('meta.balance_kobo', 700_000)
            ->assertJsonPath('meta.idempotent_replay', false);

        // Never leak private data.
        $response->assertJsonMissingPath('data.idempotency_key');
        $this->assertStringNotContainsString($bob->email, $response->getContent());

        $reference = $response->json('data.reference');
        $this->assertStringStartsWith('TR-', $reference);

        // Balances.
        $this->assertSame(700_000, $this->balanceOf($alice));
        $this->assertSame(300_000, $this->balanceOf($bob));

        // Transfer row.
        $transfer = Transfer::sole();
        $this->assertSame($alice->wallet->id, $transfer->sender_wallet_id);
        $this->assertSame($bob->wallet->id, $transfer->recipient_wallet_id);
        $this->assertSame('tr-1', $transfer->idempotency_key);

        // Ledger: 1 transaction, debit Alice's account, credit Bob's.
        $ledgerTx = LedgerTransaction::sole();
        $this->assertSame('transfer', $ledgerTx->type);
        $this->assertSame($reference, $ledgerTx->reference);
        $this->assertSame($ledgerTx->id, $transfer->ledger_transaction_id);

        $aliceAccount = LedgerAccount::where('account_code', "WALLET-{$alice->wallet->id}")->firstOrFail();
        $bobAccount = LedgerAccount::where('account_code', "WALLET-{$bob->wallet->id}")->firstOrFail();

        $debit = LedgerEntry::where('direction', 'debit')->sole();
        $credit = LedgerEntry::where('direction', 'credit')->sole();
        $this->assertSame($aliceAccount->id, $debit->ledger_account_id);
        $this->assertSame($bobAccount->id, $credit->ledger_account_id);
        $this->assertSame(300_000, $debit->amount_kobo);
        $this->assertSame(300_000, $credit->amount_kobo);

        // Wallet history: a debit for Alice, a credit for Bob.
        $aliceRow = $alice->wallet->transactions()->where('type', 'debit')->sole();
        $bobRow = $bob->wallet->transactions()->where('type', 'credit')->sole();
        $this->assertSame(300_000, $aliceRow->amount_kobo);
        $this->assertSame(300_000, $bobRow->amount_kobo);
        $this->assertTrue(Str::isUuid($aliceRow->reference));
        $this->assertStringContainsString($reference, $aliceRow->description);
        $this->assertStringContainsString($reference, $bobRow->description);

        // The existing wallet endpoint agrees.
        $this->getJson('/api/v1/wallet')->assertJsonPath('data.balance_kobo', 700_000);
    }

    public function test_sending_the_exact_balance_is_allowed(): void
    {
        $alice = $this->userWithWallet(50_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        $this->send($bob, 50_000)->assertCreated()->assertJsonPath('meta.balance_kobo', 0);
    }

    public function test_users_can_see_their_own_wallet_number_to_share_it(): void
    {
        $bob = $this->userWithWallet();
        Sanctum::actingAs($bob);

        $this->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.wallet_number', $bob->wallet->wallet_number);
    }

    public function test_sender_is_always_the_token_owner(): void
    {
        $alice = $this->userWithWallet(100_000);
        $victim = $this->userWithWallet(900_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        // Trying to sneak in someone else's wallet as the sender is ignored.
        $this->send($bob, 10_000, 'k', ['sender_wallet_number' => $victim->wallet->wallet_number])
            ->assertCreated()
            ->assertJsonPath('data.sender.wallet_number', $alice->wallet->wallet_number);

        $this->assertSame(900_000, $this->balanceOf($victim));
    }

    // ---------------------------------------------------------------
    // POST /transfers — business rules
    // ---------------------------------------------------------------

    public function test_insufficient_balance_is_rejected(): void
    {
        $alice = $this->userWithWallet(10_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        $this->send($bob, 10_001)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient balance for this transfer.');

        $this->assertNoTransferWritten();
        $this->assertSame(10_000, $this->balanceOf($alice));
    }

    public function test_cannot_transfer_to_yourself(): void
    {
        $alice = $this->userWithWallet(100_000);
        Sanctum::actingAs($alice);

        $this->send($alice, 1_000)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot transfer money to your own wallet.');

        $this->assertNoTransferWritten();
    }

    public function test_unknown_recipient_wallet_number_is_rejected(): void
    {
        Sanctum::actingAs($this->userWithWallet(100_000));

        $this->postJson(self::URL, ['recipient_wallet_number' => '9doesnotexist', 'amount_kobo' => 1_000], [
            'Idempotency-Key' => 'k',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_wallet_number' => 'No account was found with this account number.']);

        $this->assertNoTransferWritten();
    }

    public function test_frozen_sender_cannot_send(): void
    {
        $alice = $this->userWithWallet(100_000, ['status' => 'frozen']);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        $this->send($bob, 1_000)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Your wallet is frozen. Only active wallets can send transfers.');

        $this->assertNoTransferWritten();
    }

    public function test_closed_recipient_cannot_receive(): void
    {
        $alice = $this->userWithWallet(100_000);
        $bob = $this->userWithWallet(0, ['status' => 'closed']);
        Sanctum::actingAs($alice);

        $this->send($bob, 1_000)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The recipient wallet cannot receive transfers right now.');

        $this->assertNoTransferWritten();
    }

    public function test_different_currencies_are_rejected(): void
    {
        $alice = $this->userWithWallet(100_000);
        $bob = $this->userWithWallet(0, ['currency' => 'USD']);
        Sanctum::actingAs($alice);

        $this->send($bob, 1_000)->assertUnprocessable();
        $this->assertNoTransferWritten();
    }

    // ---------------------------------------------------------------
    // POST /transfers — validation
    // ---------------------------------------------------------------

    public static function invalidAmounts(): array
    {
        return [
            'missing' => [null],
            'zero' => [0],
            'negative' => [-500],
            'below ₦1' => [99],
            'above ₦1,000,000' => [100_000_001],
            'decimal' => ['100.50'],
            'text' => ['one thousand'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(mixed $amount): void
    {
        $alice = $this->userWithWallet(1_000_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        $this->send($bob, $amount)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_kobo');

        $this->assertNoTransferWritten();
    }

    public function test_idempotency_key_header_is_required(): void
    {
        $alice = $this->userWithWallet(100_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        $this->send($bob, 1_000, null)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key' => 'The Idempotency-Key header is required.']);

        $this->assertNoTransferWritten();
    }

    public function test_narration_longer_than_255_characters_is_rejected(): void
    {
        $alice = $this->userWithWallet(100_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        $this->send($bob, 1_000, 'k', ['narration' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('narration');
    }

    // ---------------------------------------------------------------
    // POST /transfers — idempotency
    // ---------------------------------------------------------------

    public function test_retrying_with_the_same_key_does_not_send_twice(): void
    {
        $alice = $this->userWithWallet(1_000_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        $first = $this->send($bob, 200_000, 'retry-me')->assertCreated();

        $this->send($bob, 200_000, 'retry-me')
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.reference', $first->json('data.reference'))
            ->assertJsonPath('meta.balance_kobo', 800_000);

        $this->assertSame(1, Transfer::count());
        $this->assertSame(800_000, $this->balanceOf($alice));
        $this->assertSame(200_000, $this->balanceOf($bob));
    }

    public function test_reusing_a_key_for_a_different_amount_is_a_conflict(): void
    {
        $alice = $this->userWithWallet(1_000_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        $this->send($bob, 200_000, 'same')->assertCreated();
        $this->send($bob, 300_000, 'same')->assertStatus(409);

        $this->assertSame(1, Transfer::count());
    }

    public function test_reusing_a_key_for_a_different_recipient_is_a_conflict(): void
    {
        $alice = $this->userWithWallet(1_000_000);
        $bob = $this->userWithWallet();
        $carol = $this->userWithWallet();
        Sanctum::actingAs($alice);

        $this->send($bob, 200_000, 'same')->assertCreated();
        $this->send($carol, 200_000, 'same')->assertStatus(409);

        $this->assertSame(0, $this->balanceOf($carol));
    }

    public function test_different_senders_may_use_the_same_key(): void
    {
        $alice = $this->userWithWallet(100_000);
        $bob = $this->userWithWallet(100_000);
        $carol = $this->userWithWallet();

        Sanctum::actingAs($alice);
        $this->send($carol, 1_000, 'shared')->assertCreated();

        Sanctum::actingAs($bob);
        $this->send($carol, 1_000, 'shared')->assertCreated();

        $this->assertSame(2, Transfer::count());
    }

    // ---------------------------------------------------------------
    // Atomicity
    // ---------------------------------------------------------------

    public function test_failure_at_the_last_step_rolls_back_everything(): void
    {
        $alice = $this->userWithWallet(100_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        // Blow up on the very last insert: the recipient's credit.
        // By then the ledger, the transfer and Alice's debit are written.
        WalletTransaction::creating(function (WalletTransaction $row) {
            if (str_starts_with((string) $row->description, 'Transfer from')) {
                throw new RuntimeException('Simulated database failure');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->send($bob, 40_000);
            $this->fail('Expected the simulated failure to be thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated database failure', $e->getMessage());
        }

        $this->assertNoTransferWritten();
        $this->assertSame(100_000, $this->balanceOf($alice));
        $this->assertSame(0, $this->balanceOf($bob));
    }

    public function test_whole_ledger_stays_balanced_after_many_transfers(): void
    {
        $alice = $this->userWithWallet(500_000);
        $bob = $this->userWithWallet(500_000);

        Sanctum::actingAs($alice);
        $this->send($bob, 100_000, 'a1')->assertCreated();
        $this->send($bob, 50_000, 'a2')->assertCreated();

        Sanctum::actingAs($bob);
        $this->send($alice, 120_000, 'b1')->assertCreated();

        $this->assertSame(
            (int) LedgerEntry::where('direction', 'debit')->sum('amount_kobo'),
            (int) LedgerEntry::where('direction', 'credit')->sum('amount_kobo'),
        );

        // Money is only moved, never created: the total stays 1,000,000.
        $this->assertSame(470_000, $this->balanceOf($alice));
        $this->assertSame(530_000, $this->balanceOf($bob));
        $this->assertSame(1_000_000, $this->balanceOf($alice) + $this->balanceOf($bob));
    }

    // ---------------------------------------------------------------
    // GET /transfers
    // ---------------------------------------------------------------

    public function test_list_shows_only_my_sent_and_received_transfers(): void
    {
        $alice = $this->userWithWallet(500_000);
        $bob = $this->userWithWallet(500_000);
        $carol = $this->userWithWallet(500_000);
        $dave = $this->userWithWallet();

        Sanctum::actingAs($alice);
        $this->send($bob, 1_000, 'a1')->assertCreated();   // Alice sent

        Sanctum::actingAs($bob);
        $this->send($alice, 2_000, 'b1')->assertCreated(); // Alice received

        Sanctum::actingAs($carol);
        $this->send($dave, 3_000, 'c1')->assertCreated();  // not Alice's business

        Sanctum::actingAs($alice);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // Newest first.
            ->assertJsonPath('data.0.direction', 'received')
            ->assertJsonPath('data.0.amount_kobo', 2_000)
            ->assertJsonPath('data.1.direction', 'sent')
            ->assertJsonPath('data.1.amount_kobo', 1_000)
            ->assertJsonPath('meta.total', 2);

        $this->getJson(self::URL . '?direction=sent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.direction', 'sent');

        $this->getJson(self::URL . '?direction=received')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.direction', 'received');
    }

    public function test_list_is_paginated(): void
    {
        $alice = $this->userWithWallet(500_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        foreach (['k1', 'k2', 'k3'] as $key) {
            $this->send($bob, 1_000, $key)->assertCreated();
        }

        $this->getJson(self::URL . '?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_list_rejects_invalid_filters(): void
    {
        Sanctum::actingAs($this->userWithWallet());

        $this->getJson(self::URL . '?direction=sideways')->assertUnprocessable()->assertJsonValidationErrors('direction');
        $this->getJson(self::URL . '?per_page=500')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    // ---------------------------------------------------------------
    // GET /transfers/{id}
    // ---------------------------------------------------------------

    public function test_sender_and_recipient_can_view_a_transfer(): void
    {
        $alice = $this->userWithWallet(100_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);
        $id = $this->send($bob, 5_000, 'k', ['narration' => 'Thanks'])->json('data.id');

        $this->getJson(self::URL . "/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.direction', 'sent')
            ->assertJsonPath('data.narration', 'Thanks');

        Sanctum::actingAs($bob);
        $this->getJson(self::URL . "/{$id}")
            ->assertOk()
            ->assertJsonPath('data.direction', 'received');
    }

    public function test_other_users_cannot_view_a_transfer(): void
    {
        $alice = $this->userWithWallet(100_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);
        $id = $this->send($bob, 5_000)->json('data.id');

        // 404, not 403: outsiders cannot even tell the transfer exists.
        Sanctum::actingAs($this->userWithWallet());
        $this->getJson(self::URL . "/{$id}")->assertNotFound();
    }

    public function test_unknown_or_non_numeric_ids_return_404(): void
    {
        Sanctum::actingAs($this->userWithWallet());

        $this->getJson(self::URL . '/999999')->assertNotFound();
        $this->getJson(self::URL . '/abc')->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Rate limiting
    // ---------------------------------------------------------------

    public function test_more_than_ten_transfers_per_minute_are_throttled(): void
    {
        $alice = $this->userWithWallet(1_000_000);
        $bob = $this->userWithWallet();
        Sanctum::actingAs($alice);

        for ($i = 1; $i <= 10; $i++) {
            $this->send($bob, 100, "k-{$i}")->assertCreated();
        }

        $this->send($bob, 100, 'k-11')->assertTooManyRequests();
        $this->assertSame(10, Transfer::count());
    }
}