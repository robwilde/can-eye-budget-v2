<?php

declare(strict_types=1);

namespace App\Exceptions\Statement;

use RuntimeException;

/** The line is not in a state the requested resolution applies to. */
final class StatementLineNotResolvable extends RuntimeException {}
