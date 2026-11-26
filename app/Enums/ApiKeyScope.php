<?php

declare(strict_types=1);

namespace App\Enums;

enum ApiKeyScope: string
{
    case Read = 'read';
    case ReadWrite = 'read_write';

    public const string ABILITY_READ = 'read';

    public const string ABILITY_WRITE = 'write';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read only',
            self::ReadWrite => 'Read and write',
        };
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Read => [self::ABILITY_READ],
            self::ReadWrite => [self::ABILITY_READ, self::ABILITY_WRITE],
        };
    }
}
