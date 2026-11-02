<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** An idempotency key was submitted again with different details; nothing was written. */
final class IdempotencyKeyReusedException extends RuntimeException {}
