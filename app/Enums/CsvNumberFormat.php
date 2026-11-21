<?php

declare(strict_types=1);

namespace App\Enums;

enum CsvNumberFormat: string
{
    case CommaThousandsDotDecimal = 'comma_dot';
    case DotThousandsCommaDecimal = 'dot_comma';
    case SpaceThousandsCommaDecimal = 'space_comma';
    case CommaThousandsOnly = 'comma_only';

    public function label(): string
    {
        return match ($this) {
            self::CommaThousandsDotDecimal => '1,234.56',
            self::DotThousandsCommaDecimal => '1.234,56',
            self::SpaceThousandsCommaDecimal => '1 234,56',
            self::CommaThousandsOnly => '1,234',
        };
    }
}
