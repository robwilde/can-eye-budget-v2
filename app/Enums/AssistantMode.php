<?php

declare(strict_types=1);

namespace App\Enums;

enum AssistantMode: string
{
    case BuiltIn = 'built_in';
    case McpOnly = 'mcp_only';
    case Off = 'off';

    public function chatEnabled(): bool
    {
        return $this === self::BuiltIn;
    }

    public function label(): string
    {
        return match ($this) {
            self::BuiltIn => 'Built-in chat',
            self::McpOnly => 'MCP only (no in-app chat)',
            self::Off => 'Off',
        };
    }
}
