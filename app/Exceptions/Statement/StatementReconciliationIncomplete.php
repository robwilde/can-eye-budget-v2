<?php

declare(strict_types=1);

namespace App\Exceptions\Statement;

use RuntimeException;

/** A reconciliation can only close once every line is checked. */
final class StatementReconciliationIncomplete extends RuntimeException {}
