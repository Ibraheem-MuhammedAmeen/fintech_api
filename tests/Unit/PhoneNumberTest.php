<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test: no database, no Laravel app needed.
 * Run with:  php artisan test --filter=PhoneNumberTest
 */
class PhoneNumberTest extends TestCase
{
    public static function validNumbers(): array
    {
        return [
            'local' => ['08031234567', '08031234567'],
            'local with spaces' => ['0803 123 4567', '08031234567'],
            'local with dashes' => ['0803-123-4567', '08031234567'],
            'international +234' => ['+2348031234567', '08031234567'],
            'international with spaces' => ['+234 803 123 4567', '08031234567'],
            'international without +' => ['2348031234567', '08031234567'],
            'account number (no leading 0)' => ['8031234567', '08031234567'],
            '070 number' => ['07012345678', '07012345678'],
            '081 number' => ['08112345678', '08112345678'],
            '090 number' => ['09012345678', '09012345678'],
            '091 number' => ['09161234567', '09161234567'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_valid_numbers_are_normalised(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public static function invalidNumbers(): array
    {
        return [
            'too short' => ['0803123'],
            'too long' => ['080312345678'],
            'landline-style prefix' => ['01234567890'],
            'wrong second digit' => ['06031234567'],
            'letters' => ['0803abc4567'],
            'empty' => [''],
            'old random wallet number' => ['99yPeuzHz1'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_numbers_return_null(string $input): void
    {
        $this->assertNull(PhoneNumber::normalize($input));
    }

    public function test_account_number_is_phone_without_leading_zero(): void
    {
        $this->assertSame('8031234567', PhoneNumber::toAccountNumber('08031234567'));
    }

    public function test_any_phone_format_becomes_the_same_account_number(): void
    {
        foreach (['08031234567', '+234 803 123 4567', '8031234567', '2348031234567'] as $input) {
            $this->assertSame('8031234567', PhoneNumber::accountNumberFromInput($input), $input);
        }
    }

    public function test_old_non_phone_wallet_numbers_pass_through_unchanged(): void
    {
        $this->assertSame('99yPeuzHz1', PhoneNumber::accountNumberFromInput('99yPeuzHz1'));
        $this->assertSame('99yPeuzHz1', PhoneNumber::accountNumberFromInput('  99yPeuzHz1 '));
    }
}
