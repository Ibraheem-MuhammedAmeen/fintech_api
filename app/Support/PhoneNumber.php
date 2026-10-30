<?php

namespace App\Support;

/**
 * Nigerian mobile numbers, OPay/PalmPay style.
 *
 * People type phone numbers in many ways. This class turns all of them
 * into ONE standard form, so "0803 123 4567", "+2348031234567" and
 * "8031234567" are recognised as the same person.
 *
 *   phone (stored in users.phone)        : 08031234567   (11 digits, starts with 0)
 *   account number (wallets.wallet_number): 8031234567    (phone without the leading 0)
 */
class PhoneNumber
{
    /**
     * Valid Nigerian mobile number in standard form: 0 + 7/8/9 + 0/1 + 8 digits.
     * Covers 070x, 080x, 081x, 090x, 091x numbers.
     */
    private const LOCAL_PATTERN = '/^0[789][01]\d{8}$/';

    /**
     * Turn any common way of writing a Nigerian mobile number into "08031234567".
     * Returns null if it is not a valid Nigerian mobile number.
     *
     *   "08031234567"      -> "08031234567"
     *   "0803 123 4567"    -> "08031234567"
     *   "+234 803 123 4567"-> "08031234567"
     *   "2348031234567"    -> "08031234567"
     *   "8031234567"       -> "08031234567"   (an account number)
     *   "12345"            -> null
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        // Keep only digits: removes spaces, dashes, brackets and the "+".
        $digits = preg_replace('/\D/', '', $input);

        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            $digits = '0' . substr($digits, 3);      // 2348031234567 -> 08031234567
        } elseif (strlen($digits) === 10) {
            $digits = '0' . $digits;                 // 8031234567   -> 08031234567
        }

        return preg_match(self::LOCAL_PATTERN, $digits) ? $digits : null;
    }

    /** "08031234567" -> "8031234567" (the wallet's account number). */
    public static function toAccountNumber(string $normalizedPhone): string
    {
        return substr($normalizedPhone, 1);
    }

    /**
     * Turn whatever the sender typed into an account number to look up.
     *
     * - A phone number in any format -> its 10-digit account number.
     * - Anything else is returned unchanged, so OLD wallet numbers created
     *   before phone numbers were added (e.g. "99yPeuzHz1") still work.
     */
    public static function accountNumberFromInput(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $phone = self::normalize($input);

        return $phone !== null ? self::toAccountNumber($phone) : trim($input);
    }
}
