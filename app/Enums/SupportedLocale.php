<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportedLocale: string
{
    case English = 'en';
    case German = 'de';

    /**
     * The first supported language in an Accept-Language header, honouring the order the browser
     * sent and ignoring any with a zero quality value.
     */
    public static function fromAcceptLanguage(?string $header): ?self
    {
        if ($header === null || mb_trim($header) === '') {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $position => $part) {
            $segments = explode(';', mb_trim($part));
            $tag = mb_strtolower(mb_trim($segments[0]));
            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=/i', $parameter) !== 1) {
                    continue;
                }

                $quality = preg_match('/^\s*q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\s*$/i', $parameter, $match) === 1 ? (float) $match[1] : 0.0;
            }

            if ($tag === '' || $tag === '*' || $quality <= 0.0) {
                continue;
            }

            $candidates[] = ['tag' => $tag, 'quality' => $quality, 'position' => $position];
        }

        usort($candidates, static fn (array $a, array $b): int => [$b['quality'], $a['position']] <=> [$a['quality'], $b['position']]);

        foreach ($candidates as $candidate) {
            $primary = explode('-', str_replace('_', '-', $candidate['tag']))[0];
            $locale = self::tryFrom($primary);

            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::German => 'Deutsch',
        };
    }
}
