<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The reports page's sections. Case order is the default order; the user's saved order only
 * rearranges them.
 */
enum ReportSection: string
{
    case Spending = 'spending';
    case NetWorth = 'net-worth';
    case Trends = 'trends';

    /**
     * The sections in the saved order: unknown and repeated keys are ignored and a section the order
     * does not mention is appended in its default place.
     *
     * @param  list<string>  $stored
     * @return list<self>
     */
    public static function ordered(array $stored): array
    {
        $ordered = [];

        foreach ($stored as $key) {
            $section = self::tryFrom($key);

            if ($section !== null && ! in_array($section, $ordered, true)) {
                $ordered[] = $section;
            }
        }

        foreach (self::cases() as $section) {
            if (! in_array($section, $ordered, true)) {
                $ordered[] = $section;
            }
        }

        return $ordered;
    }

    public function label(): string
    {
        return match ($this) {
            self::Spending => 'Income and spending',
            self::NetWorth => 'Net worth',
            self::Trends => 'Trends',
        };
    }
}
