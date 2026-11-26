<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Env;

enum RegistrationMode: string
{
    case Open = 'open';
    case Closed = 'closed';
    case InviteOnly = 'invite_only';

    /**
     * BUDGET_REGISTRATION_MODE wins when it is set. Without it the older FORTIFY_REGISTRATION_ENABLED
     * switch keeps its meaning (false closes registration, anything else opens it), so an install that
     * only set that variable behaves as before. An unrecognised mode closes registration.
     */
    public static function fromEnvironment(): self
    {
        return self::resolve(Env::get('BUDGET_REGISTRATION_MODE'), Env::get('FORTIFY_REGISTRATION_ENABLED', true));
    }

    public static function resolve(mixed $mode, mixed $legacyEnabled): self
    {
        if (is_string($mode) && mb_trim($mode) !== '') {
            return self::tryFrom(mb_strtolower(mb_trim($mode))) ?? self::Closed;
        }

        return $legacyEnabled ? self::Open : self::Closed;
    }

    public static function current(): self
    {
        $mode = config('budget.registration_mode');

        return is_string($mode) ? (self::tryFrom($mode) ?? self::Closed) : self::Closed;
    }

    public function allowsRegistration(): bool
    {
        return $this !== self::Closed;
    }

    public function requiresInvite(): bool
    {
        return $this === self::InviteOnly;
    }
}
