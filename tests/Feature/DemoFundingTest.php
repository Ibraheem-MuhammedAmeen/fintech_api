<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFundingRequest;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs against the test database set in phpunit.xml: either in-memory
 * SQLite or a separate MySQL database such as fintech_db_test.
 * NEVER against fintech_db (tests/TestCase.php refuses that).
 *
 * Run with:  php artisan test --filter=DemoFundingTest
 */
class DemoFundingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/wallet/fund-demo';

    protected function setUp(): void
    {
        parent::setUp();

        config(['fintech.demo_funding.enabled' => true]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function userWithWallet(array $walletAttributes = []): User
    {
        $user = User::factory()->create();

        $user->wallet()->create(array_merge([
            'wallet_number' => '9' . Str::random(9),
            'currency' => 'NGN',
            'status' => 'active',
        ], $walletAttributes));

        return $user;
    }

    private function fund(int|string|null $amount, ?string $key = 'key-1', array $extraBody = [])
    {
        $headers = ['Accept' => 'application/json'];

        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $this->postJson(self::URL, array_merge(['amount_kobo' => $amount], $extraBody), $headers);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, LedgerTransaction::count());
        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame(0, WalletFundingRequest::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    // ---------------------------------------------------------------
    // Access control
    // ---------------------------------------------------------------

    public function test_guests_are_rejected(): void
    {
        $this->fund(500000)->assertUnauthorized();
        $this->assertNothingWritten();
    }

    public function test_guests_without_accept_header_get_json_401_not_a_crash(): void
    {
        // Regression test: this used to be a 500 "Route [login] not defined".
        $this->post(self::URL, ['amount_kobo' => 500000])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_endpoint_is_hidden_when_demo_funding_is_disabled(): void
    {
        config(['fintech.demo_funding.enabled' => false]);
        Sanctum::actingAs($this->userWithWallet());

        $this->fund(500000)->assertNotFound();
        $this->assertNothingWritten();
    }

    public function test_funding_works_in_production_when_enabled(): void
    {
        // Dummy money never leaves the app, so production is allowed.
        $this->app->detectEnvironment(fn () => 'production');
        Sanctum::actingAs($this->userWithWallet());

        $this->fund(500000)->assertCreated();
    }

    // ---------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------

    public function test_funding_writes_balanced_ledger_and_credits_wallet(): void
    {
        $user = $this->userWithWallet();
        $wallet = $user->wallet;
        Sanctum::actingAs($user);

        $response = $this->fund(500000)
            ->assertCreated()
            ->assertJsonPath('message', 'Demo funding successful.')
            ->assertJsonPath('data.wallet_id', $wallet->id)
            ->assertJsonPath('data.amount_kobo', 500000)
            ->assertJsonPath('data.balance_kobo', 500000)
            ->assertJsonPath('data.idempotent_replay', false);

        $reference = $response->json('data.reference');
        $this->assertStringStartsWith('DF-', $reference);

        // Exactly one of each record, plus two entries.
        $this->assertSame(1, LedgerTransaction::count());
        $this->assertSame(2, LedgerEntry::count());
        $this->assertSame(1, WalletFundingRequest::count());
        $this->assertSame(1, WalletTransaction::count());

        // Ledger transaction.
        $ledgerTx = LedgerTransaction::first();
        $this->assertSame($reference, $ledgerTx->reference);
        $this->assertSame('demo_funding', $ledgerTx->type);

        // Ledger accounts have the right codes and types.
        $source = LedgerAccount::where('account_code', 'DEMO-FUNDING-NGN')->firstOrFail();
        $this->assertSame('asset', $source->account_type);
        $this->assertNull($source->wallet_id);

        $walletAccount = LedgerAccount::where('account_code', "WALLET-{$wallet->id}")->firstOrFail();
        $this->assertSame('liability', $walletAccount->account_type);
        $this->assertSame($wallet->id, $walletAccount->wallet_id);

        // Debit the demo source, credit the wallet, same amount.
        $debit = LedgerEntry::where('direction', 'debit')->firstOrFail();
        $credit = LedgerEntry::where('direction', 'credit')->firstOrFail();
        $this->assertSame($source->id, $debit->ledger_account_id);
        $this->assertSame($walletAccount->id, $credit->ledger_account_id);
        $this->assertSame(500000, $debit->amount_kobo);
        $this->assertSame(500000, $credit->amount_kobo);
        $this->assertNotNull($debit->created_at);

        // Funding request is linked to the ledger transaction.
        $funding = WalletFundingRequest::first();
        $this->assertSame($ledgerTx->id, $funding->ledger_transaction_id);
        $this->assertSame('key-1', $funding->idempotency_key);

        // Wallet credit uses a bare UUID (the column only holds 36 chars).
        $credit = WalletTransaction::first();
        $this->assertSame('credit', $credit->type);
        $this->assertSame(500000, $credit->amount_kobo);
        $this->assertTrue(Str::isUuid($credit->reference));
        $this->assertSame('DF-' . $credit->reference, $reference);

        // The existing wallet endpoint sees the new balance.
        $this->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance_kobo', 500000);
    }

    public function test_funding_adds_to_an_existing_balance(): void
    {
        $user = $this->userWithWallet();
        $user->wallet->transactions()->create([
            'reference' => (string) Str::uuid(),
            'type' => 'credit',
            'amount_kobo' => 250000,
            'description' => 'Earlier credit',
        ]);
        Sanctum::actingAs($user);

        $this->fund(100000)
            ->assertCreated()
            ->assertJsonPath('data.balance_kobo', 350000);
    }

    public function test_ledger_accounts_are_reused_across_fundings(): void
    {
        $alice = $this->userWithWallet();
        $bob = $this->userWithWallet();

        Sanctum::actingAs($alice);
        $this->fund(10000, 'a-1')->assertCreated();
        $this->fund(20000, 'a-2')->assertCreated()->assertJsonPath('data.balance_kobo', 30000);

        Sanctum::actingAs($bob);
        $this->fund(5000, 'b-1')->assertCreated()->assertJsonPath('data.balance_kobo', 5000);

        // 1 shared demo source + 1 account per wallet.
        $this->assertSame(3, LedgerAccount::count());
        $this->assertSame(1, LedgerAccount::where('account_code', 'DEMO-FUNDING-NGN')->count());
        $this->assertSame(3, LedgerTransaction::count());
        $this->assertSame(6, LedgerEntry::count());

        // Whole ledger still balances.
        $this->assertSame(
            (int) LedgerEntry::where('direction', 'debit')->sum('amount_kobo'),
            (int) LedgerEntry::where('direction', 'credit')->sum('amount_kobo'),
        );
    }

    // ---------------------------------------------------------------
    // Security: wallet always comes from the token
    // ---------------------------------------------------------------

    public function test_wallet_id_in_body_is_ignored(): void
    {
        $victim = $this->userWithWallet();
        $attacker = $this->userWithWallet();
        Sanctum::actingAs($attacker);

        $this->fund(500000, 'k', ['wallet_id' => $victim->wallet->id])
            ->assertCreated()
            ->assertJsonPath('data.wallet_id', $attacker->wallet->id);

        $this->assertSame(0, $victim->wallet->transactions()->count());
    }

    // ---------------------------------------------------------------
    // Validation
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
            'text' => ['five thousand'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(mixed $amount): void
    {
        Sanctum::actingAs($this->userWithWallet());

        $this->fund($amount)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_kobo');

        $this->assertNothingWritten();
    }

    public function test_idempotency_key_header_is_required(): void
    {
        Sanctum::actingAs($this->userWithWallet());

        $this->fund(500000, null)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key' => 'The Idempotency-Key header is required.']);

        $this->assertNothingWritten();
    }

    public function test_idempotency_key_with_bad_characters_is_rejected(): void
    {
        Sanctum::actingAs($this->userWithWallet());

        $this->fund(500000, 'has spaces & symbols!')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertNothingWritten();
    }

    // ---------------------------------------------------------------
    // Wallet state
    // ---------------------------------------------------------------

    public function test_frozen_wallet_cannot_be_funded(): void
    {
        Sanctum::actingAs($this->userWithWallet(['status' => 'frozen']));

        $this->fund(500000)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Your wallet is frozen. Only active wallets can be funded.');

        $this->assertNothingWritten();
    }

    public function test_non_ngn_wallet_cannot_be_funded(): void
    {
        Sanctum::actingAs($this->userWithWallet(['currency' => 'USD']));

        $this->fund(500000)->assertUnprocessable();
        $this->assertNothingWritten();
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_retrying_with_same_key_does_not_fund_twice(): void
    {
        Sanctum::actingAs($this->userWithWallet());

        $first = $this->fund(500000, 'retry-me')->assertCreated();

        $this->fund(500000, 'retry-me')
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.reference', $first->json('data.reference'))
            ->assertJsonPath('data.funding_request_id', $first->json('data.funding_request_id'))
            ->assertJsonPath('data.balance_kobo', 500000);

        $this->assertSame(1, WalletFundingRequest::count());
        $this->assertSame(1, LedgerTransaction::count());
        $this->assertSame(2, LedgerEntry::count());
        $this->assertSame(1, WalletTransaction::count());
    }

    public function test_reusing_a_key_with_a_different_amount_is_a_conflict(): void
    {
        Sanctum::actingAs($this->userWithWallet());

        $this->fund(500000, 'same-key')->assertCreated();
        $this->fund(900000, 'same-key')->assertStatus(409);

        $this->assertSame(1, WalletTransaction::count());
    }

    public function test_different_users_may_use_the_same_key(): void
    {
        Sanctum::actingAs($this->userWithWallet());
        $this->fund(1000, 'shared')->assertCreated();

        Sanctum::actingAs($this->userWithWallet());
        $this->fund(1000, 'shared')->assertCreated();

        $this->assertSame(2, WalletFundingRequest::count());
    }

    // ---------------------------------------------------------------
    // Atomicity: all or nothing
    // ---------------------------------------------------------------

    public function test_failure_at_the_last_step_rolls_back_everything(): void
    {
        Sanctum::actingAs($this->userWithWallet());

        // Make the very last insert (the wallet credit) blow up,
        // AFTER the ledger rows and funding request were written.
        WalletTransaction::creating(function () {
            throw new RuntimeException('Simulated database failure');
        });

        $this->withoutExceptionHandling();

        try {
            $this->fund(500000);
            $this->fail('Expected the simulated failure to be thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated database failure', $e->getMessage());
        }

        // Nothing from the half-finished request may remain.
        $this->assertNothingWritten();
        $this->assertSame(0, LedgerAccount::count());
    }

    // ---------------------------------------------------------------
    // Rate limiting
    // ---------------------------------------------------------------

    public function test_more_than_five_requests_per_minute_are_throttled(): void
    {
        Sanctum::actingAs($this->userWithWallet());

        for ($i = 1; $i <= 5; $i++) {
            $this->fund(100, "k-{$i}")->assertCreated();
        }

        $this->fund(100, 'k-6')->assertTooManyRequests();
        $this->assertSame(5, WalletTransaction::count());
    }
}