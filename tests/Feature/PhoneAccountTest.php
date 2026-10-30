<?php

namespace Tests\Feature;

use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phone number as account number (OPay/PalmPay style):
 *   - registering with a phone creates account number = phone without leading 0
 *   - GET /accounts/{number} shows the owner's name before sending
 *   - transfers accept the recipient's phone in any format
 *
 * Runs against fintech_db_test (phpunit.xml), never fintech_db.
 */
class PhoneAccountTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function register(array $overrides = [])
    {
        return $this->postJson('/api/v1/register', array_merge([
            'name' => 'Bola Adeyemi',
            'email' => 'bola' . Str::random(6) . '@example.com',
            'phone' => '08031234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides));
    }

    /** A user created directly (without the API), optionally with a phone. */
    private function userWithWallet(int $balanceKobo = 0, ?string $phone = null, string $name = 'Test User'): User
    {
        $user = User::factory()->create(['name' => $name, 'phone' => $phone]);

        $wallet = $user->wallet()->create([
            'wallet_number' => $phone ? substr($phone, 1) : '9' . Str::random(9),
            'currency' => 'NGN',
            'status' => 'active',
        ]);

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

    // ---------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------

    public function test_registering_with_a_phone_creates_an_opay_style_account_number(): void
    {
        $response = $this->register(['phone' => '08031234567'])
            ->assertCreated()
            ->assertJsonPath('data.account_number', '8031234567')
            ->assertJsonPath('data.user.phone', '08031234567');

        $this->assertNotEmpty($response->json('data.token'));

        $user = User::where('phone', '08031234567')->firstOrFail();
        $this->assertSame('8031234567', $user->wallet->wallet_number);

        // The user sees their account number on the wallet screen.
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/wallet')->assertJsonPath('data.wallet_number', '8031234567');
    }

    public function test_phone_is_stored_in_one_standard_format_whatever_was_typed(): void
    {
        $this->register(['phone' => '+234 803 123 4567'])
            ->assertCreated()
            ->assertJsonPath('data.user.phone', '08031234567')
            ->assertJsonPath('data.account_number', '8031234567');
    }

    public function test_phone_is_required(): void
    {
        $this->register(['phone' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertSame(0, User::count());
    }

    public function test_invalid_phone_numbers_are_rejected(): void
    {
        foreach (['12345', '0603123456', 'not-a-phone'] as $phone) {
            $this->register(['phone' => $phone])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['phone' => 'Enter a valid Nigerian mobile number, e.g. 08031234567.']);
        }

        $this->assertSame(0, User::count());
    }

    public function test_a_phone_number_can_only_have_one_account_even_typed_differently(): void
    {
        $this->register(['phone' => '08031234567'])->assertCreated();

        // Same number in international format must still be caught.
        $this->register(['phone' => '+2348031234567'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'This phone number is already linked to an account.']);

        $this->assertSame(1, User::count());
        $this->assertSame(1, Wallet::count());
    }

    // ---------------------------------------------------------------
    // GET /accounts/{account_number}
    // ---------------------------------------------------------------

    public function test_lookup_shows_only_the_account_name(): void
    {
        $bola = $this->userWithWallet(0, '08031234567', 'Bola Adeyemi');
        Sanctum::actingAs($this->userWithWallet());

        $response = $this->getJson('/api/v1/accounts/8031234567')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'account_number' => '8031234567',
                    'account_name' => 'Bola Adeyemi',
                ],
            ]);

        // Never leak private details.
        $this->assertStringNotContainsString($bola->email, $response->getContent());
        $this->assertStringNotContainsString('08031234567', $response->getContent());
    }

    public function test_lookup_accepts_the_phone_number_in_any_format(): void
    {
        $this->userWithWallet(0, '08031234567', 'Bola Adeyemi');
        Sanctum::actingAs($this->userWithWallet());

        foreach (['08031234567', '2348031234567', '8031234567'] as $input) {
            $this->getJson("/api/v1/accounts/{$input}")
                ->assertOk()
                ->assertJsonPath('data.account_name', 'Bola Adeyemi');
        }
    }

    public function test_lookup_still_works_for_old_wallet_numbers(): void
    {
        $old = $this->userWithWallet(0, null, 'Ameen');
        Sanctum::actingAs($this->userWithWallet());

        $this->getJson("/api/v1/accounts/{$old->wallet->wallet_number}")
            ->assertOk()
            ->assertJsonPath('data.account_name', 'Ameen');
    }

    public function test_lookup_of_unknown_number_returns_404(): void
    {
        Sanctum::actingAs($this->userWithWallet());

        $this->getJson('/api/v1/accounts/8099999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'No account was found with this account number.');
    }

    public function test_lookup_requires_login(): void
    {
        $this->getJson('/api/v1/accounts/8031234567')->assertUnauthorized();
    }

    public function test_lookup_is_rate_limited(): void
    {
        Sanctum::actingAs($this->userWithWallet());

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/accounts/8099999999')->assertNotFound();
        }

        $this->getJson('/api/v1/accounts/8099999999')->assertTooManyRequests();
    }

    // ---------------------------------------------------------------
    // Transfers by phone number
    // ---------------------------------------------------------------

    public function test_can_send_money_using_the_recipients_phone_in_any_format(): void
    {
        $alice = $this->userWithWallet(1_000_000, '09012345678', 'Alice');
        $bola = $this->userWithWallet(0, '08031234567', 'Bola Adeyemi');
        Sanctum::actingAs($alice);

        $formats = ['08031234567', '+234 803 123 4567', '8031234567'];

        foreach ($formats as $i => $recipient) {
            $this->postJson('/api/v1/transfers', [
                'recipient_wallet_number' => $recipient,
                'amount_kobo' => 1_000,
            ], ['Idempotency-Key' => "phone-{$i}"])
                ->assertCreated()
                ->assertJsonPath('data.recipient.wallet_number', '8031234567')
                ->assertJsonPath('data.recipient.name', 'Bola Adeyemi');
        }

        $this->assertSame(3, Transfer::where('recipient_wallet_id', $bola->wallet->id)->count());
    }

    public function test_can_still_send_to_an_old_wallet_number(): void
    {
        $alice = $this->userWithWallet(100_000, '09012345678');
        $old = $this->userWithWallet(0, null, 'Ameen');
        Sanctum::actingAs($alice);

        $this->postJson('/api/v1/transfers', [
            'recipient_wallet_number' => $old->wallet->wallet_number,
            'amount_kobo' => 1_000,
        ], ['Idempotency-Key' => 'old-1'])->assertCreated();
    }

    public function test_sending_to_your_own_phone_number_is_rejected(): void
    {
        $alice = $this->userWithWallet(100_000, '09012345678');
        Sanctum::actingAs($alice);

        $this->postJson('/api/v1/transfers', [
            'recipient_wallet_number' => '+2349012345678',
            'amount_kobo' => 1_000,
        ], ['Idempotency-Key' => 'self-1'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot transfer money to your own wallet.');
    }

    public function test_sending_to_an_unregistered_phone_number_is_rejected(): void
    {
        Sanctum::actingAs($this->userWithWallet(100_000, '09012345678'));

        $this->postJson('/api/v1/transfers', [
            'recipient_wallet_number' => '08099999999',
            'amount_kobo' => 1_000,
        ], ['Idempotency-Key' => 'nobody-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_wallet_number' => 'No account was found with this account number.']);
    }
}
