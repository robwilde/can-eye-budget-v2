<?php

declare(strict_types=1);

namespace App\Support;

final class AmountParser
{
    public static function parse(string $input): AmountParseResult
    {
        $stripped = $input;

        while (preg_match('/\([^()]*\)/', $stripped)) {
            $stripped = preg_replace('/\([^()]*\)/', '', $stripped);
        }

        $stripped = mb_trim($stripped);

        if ($stripped === '') {
            return new AmountParseResult(0, '');
        }

        if (! preg_match('/^([\d.\s*+\-\/]+)/', $stripped, $matches)) {
            return new AmountParseResult(0, $stripped);
        }

        $expression = mb_trim($matches[1]);
        $description = mb_trim(mb_substr($stripped, mb_strlen($matches[1])));
        $amount = self::evaluate($expression);
        $cents = (int) round($amount * 100);

        return new AmountParseResult($cents, $description);
    }

    private static function evaluate(string $expression): float
    {
        $tokens = self::tokenize($expression);

        if ($tokens === []) {
            return 0.0;
        }

        $tokens = self::resolveMultiplicationAndDivision($tokens);

        return self::resolveAdditionAndSubtraction($tokens);
    }

    /** @return list<float|string> */
    private static function tokenize(string $expression): array
    {
        $cleaned = preg_replace('/\s+/', '', $expression);

        if (preg_match('/^[+\-]/', (string) $cleaned)) {
            $cleaned = '0'.$cleaned;
        }

        preg_match_all('/(\d*\.?\d+|[+\-*\/])/', (string) $cleaned, $matches);

        $tokens = [];

        foreach ($matches[0] as $token) {
            $tokens[] = is_numeric($token) ? (float) $token : $token;
        }

        return $tokens;
    }

    /**
     * @param  list<float|string>  $tokens
     * @return list<float|string>
     */
    private static function resolveMultiplicationAndDivision(array $tokens): array
    {
        $result = [$tokens[0]];

        for ($i = 1, $iMax = count($tokens); $i < $iMax; $i += 2) {
            $operator = $tokens[$i];
            $right = $tokens[$i + 1] ?? 0.0;

            if ($operator === '*') {
                $result[count($result) - 1] = (float) $result[count($result) - 1] * (float) $right;
            } elseif ($operator === '/') {
                $result[count($result) - 1] = (float) $right === 0.0
                    ? 0.0
                    : (float) $result[count($result) - 1] / (float) $right;
            } else {
                $result[] = $operator;
                $result[] = $right;
            }
        }

        return array_values($result);
    }

    /** @param list<float|string> $tokens */
    private static function resolveAdditionAndSubtraction(array $tokens): float
    {
        $total = (float) $tokens[0];

        for ($i = 1, $iMax = count($tokens); $i < $iMax; $i += 2) {
            $operator = $tokens[$i];
            $right = (float) ($tokens[$i + 1] ?? 0.0);

            if ($operator === '+') {
                $total += $right;
            } elseif ($operator === '-') {
                $total -= $right;
            }
        }

        return $total;
    }
}
