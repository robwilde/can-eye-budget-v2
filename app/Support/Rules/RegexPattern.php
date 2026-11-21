<?php

declare(strict_types=1);

namespace App\Support\Rules;

final class RegexPattern
{
    public const int MAX_LENGTH = 200;

    /**
     * Whether the case-insensitive pattern matches the subject. A pattern that does not
     * compile, or a match that fails (backtrack limit, invalid UTF-8), counts as no match.
     */
    public static function matches(string $pattern, string $subject): bool
    {
        $regex = self::delimited($pattern);

        return $regex !== null && @preg_match($regex, $subject) === 1;
    }

    public static function compiles(string $pattern): bool
    {
        $regex = self::delimited($pattern);

        return $regex !== null && self::isCompilable($regex);
    }

    public static function hasBackReference(string $pattern): bool
    {
        return preg_match('/(?<!\\\\)(?:\\\\\\\\)*\\\\(?:[1-9]|g|k[<\'{])|\(\?P=/u', $pattern) === 1;
    }

    private static function isCompilable(string $regex): bool
    {
        return @preg_match($regex, '') !== false;
    }

    private static function delimited(string $pattern): ?string
    {
        $body = preg_replace('/\\\\.(*SKIP)(*FAIL)|\//su', '\\/', $pattern);

        return $body === null ? null : '/'.$body.'/iu';
    }
}
