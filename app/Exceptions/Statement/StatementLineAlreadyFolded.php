<?php

declare(strict_types=1);

namespace App\Exceptions\Statement;

use RuntimeException;

/** The statement row exists as a fee folded into its parent purchase; importing it would double-count the fee. */
final class StatementLineAlreadyFolded extends RuntimeException {}
