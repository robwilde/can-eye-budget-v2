<?php

declare(strict_types=1);

namespace App\Exceptions\Statement;

use RuntimeException;

/** A closed reconciliation is read-only until it is reopened. */
final class StatementReconciliationClosed extends RuntimeException {}
