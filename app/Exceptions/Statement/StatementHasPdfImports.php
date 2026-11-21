<?php

declare(strict_types=1);

namespace App\Exceptions\Statement;

use RuntimeException;

/** A statement that still has PDF imports cannot be deleted. */
final class StatementHasPdfImports extends RuntimeException {}
