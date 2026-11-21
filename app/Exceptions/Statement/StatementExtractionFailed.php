<?php

declare(strict_types=1);

namespace App\Exceptions\Statement;

use RuntimeException;

/** The AI provider could not extract the statement; the message is safe to show the user. */
final class StatementExtractionFailed extends RuntimeException {}
