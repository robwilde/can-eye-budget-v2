<?php

declare(strict_types=1);

namespace App\Exceptions\Assistant;

use App\Enums\AssistantErrorKind;
use RuntimeException;

/**
 * A failed model call. The message never carries the provider's response body, the prompt or a key.
 */
final class LlmException extends RuntimeException
{
    public function __construct(public readonly AssistantErrorKind $kind, ?int $status = null)
    {
        parent::__construct('LLM call failed: '.$kind->value.($status === null ? '' : ' (HTTP '.$status.')'));
    }

    public static function forStatus(int $status): self
    {
        return new self(match (true) {
            $status === 429 => AssistantErrorKind::RateLimited,
            $status >= 500 => AssistantErrorKind::Unavailable,
            $status === 401 || $status === 403 => AssistantErrorKind::Misconfigured,
            default => AssistantErrorKind::Generic,
        }, $status);
    }

    public function isRetryable(): bool
    {
        return $this->kind === AssistantErrorKind::RateLimited || $this->kind === AssistantErrorKind::Unavailable;
    }
}
