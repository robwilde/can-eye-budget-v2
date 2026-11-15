<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A budget setup, copy, allocation or move was refused; nothing was written. */
final class BudgetPlanRefusedException extends RuntimeException {}
