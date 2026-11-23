<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PaymentUrl implements ValidationRule
{
    public const int MAX_LENGTH = 2048;

    /**
     * Trims the link and adds https:// when it has no scheme. A leading "//" takes https, and
     * "host:port" counts as a host rather than a scheme when the host has a dot or is localhost.
     */
    public static function normalise(?string $value): ?string
    {
        $value = mb_trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '//')) {
            return 'https:'.$value;
        }

        $hasScheme = preg_match('/^[a-z][a-z0-9+.\-]*:/i', $value) === 1
            && preg_match('/^(?:[^:\/?#]*\.[^:\/?#]*|localhost):\d+(?:[\/?#]|$)/i', $value) !== 1;

        return $hasScheme ? $value : 'https://'.$value;
    }

    /** Whether the link is an http(s) URL with a host and no credentials, fit to render as an href. */
    public static function isSafe(?string $value): bool
    {
        if ($value === null || $value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        if (preg_match('/[\s\x00-\x1F\x7F]/u', $value) === 1) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && in_array(mb_strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $normalised = is_string($value) ? self::normalise($value) : null;

        if ($normalised === null) {
            return;
        }

        if (mb_strlen($normalised) > self::MAX_LENGTH) {
            $fail(__('The payment link may not be longer than :max characters.', ['max' => self::MAX_LENGTH]));

            return;
        }

        if (! self::isSafe($normalised)) {
            $fail(__('Enter a payment link that starts with http:// or https://.'));
        }
    }
}
