<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CsvNumberFormat;
use App\Enums\CsvSignConvention;
use Spatie\LaravelData\Dto;

/**
 * How a CSV file writes its dates and numbers. It travels in the column mapping (as strings)
 * so it is saved with the import and the account and pre-filled on the next import.
 */
final class CsvFormat extends Dto
{
    public const string KEY_DATE = 'date_format';

    public const string KEY_NUMBER = 'number_format';

    public const string KEY_SIGN = 'sign_convention';

    public const string KEY_SKIP = 'skip_rows';

    public const string KEY_DELIMITER = 'delimiter';

    public const string KEY_INFLOW = 'type_inflow_value';

    public const string TYPE_COLUMN = 'type';

    /** @var array<string, string> PHP format => label */
    public const array DATE_FORMATS = [
        'd/m/Y' => 'DD/MM/YYYY',
        'm/d/Y' => 'MM/DD/YYYY',
        'Y-m-d' => 'YYYY-MM-DD',
        'd-m-Y' => 'DD-MM-YYYY',
        'd.m.Y' => 'DD.MM.YYYY',
        'd/m/y' => 'DD/MM/YY',
        'm/d/y' => 'MM/DD/YY',
        'd M Y' => 'DD Mon YYYY',
        'd-M-Y' => 'DD-Mon-YYYY',
        'Y-m-d H:i:s' => 'YYYY-MM-DD HH:MM:SS',
        'd/m/Y H:i' => 'DD/MM/YYYY HH:MM',
    ];

    public const array DELIMITERS = [',', ';'];

    public function __construct(
        public readonly string $dateFormat = 'd/m/Y',
        public readonly CsvNumberFormat $numberFormat = CsvNumberFormat::CommaThousandsDotDecimal,
        public readonly CsvSignConvention $signConvention = CsvSignConvention::NegativeIsDebit,
        public readonly int $skipRows = 0,
        public readonly string $delimiter = ',',
        public readonly string $typeInflowValue = 'credit',
    ) {}

    /**
     * The format a column mapping carries, or null when it has none (the legacy fixed formats).
     *
     * @param  array<string, string|null>  $mapping
     */
    public static function fromMapping(array $mapping): ?self
    {
        if (! isset($mapping[self::KEY_DATE]) || $mapping[self::KEY_DATE] === '') {
            return null;
        }

        return new self(
            dateFormat: $mapping[self::KEY_DATE],
            numberFormat: CsvNumberFormat::tryFrom((string) ($mapping[self::KEY_NUMBER] ?? '')) ?? CsvNumberFormat::CommaThousandsDotDecimal,
            signConvention: CsvSignConvention::tryFrom((string) ($mapping[self::KEY_SIGN] ?? '')) ?? CsvSignConvention::NegativeIsDebit,
            skipRows: max(0, (int) ($mapping[self::KEY_SKIP] ?? 0)),
            delimiter: in_array($mapping[self::KEY_DELIMITER] ?? ',', self::DELIMITERS, true) ? (string) ($mapping[self::KEY_DELIMITER] ?? ',') : ',',
            typeInflowValue: (string) ($mapping[self::KEY_INFLOW] ?? 'credit') ?: 'credit',
        );
    }

    /**
     * Only the format entries of a mapping, so a flow that rewrites the column choices (the
     * statement reconciliation) does not erase the format saved for the next import.
     *
     * @param  array<string, string|null>  $mapping
     * @return array<string, string|null>
     */
    public static function keepFormat(array $mapping): array
    {
        return array_intersect_key($mapping, array_flip([
            self::KEY_DATE, self::KEY_NUMBER, self::KEY_SIGN, self::KEY_SKIP, self::KEY_DELIMITER, self::KEY_INFLOW, self::TYPE_COLUMN,
        ]));
    }

    /**
     * @return array<string, string>
     */
    public function toMapping(): array
    {
        return [
            self::KEY_DATE => $this->dateFormat,
            self::KEY_NUMBER => $this->numberFormat->value,
            self::KEY_SIGN => $this->signConvention->value,
            self::KEY_SKIP => (string) $this->skipRows,
            self::KEY_DELIMITER => $this->delimiter,
            self::KEY_INFLOW => $this->typeInflowValue,
        ];
    }
}
