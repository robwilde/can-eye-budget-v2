<?php

declare(strict_types=1);

namespace App\Exceptions\Statement;

use RuntimeException;

/** The statement CSV has rows the column mapping cannot parse, so nothing was rebuilt. */
final class StatementFileUnreadable extends RuntimeException {}
