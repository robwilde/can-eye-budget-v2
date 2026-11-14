<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A goal save or lifecycle action was refused; nothing was written. */
final class GoalRefusedException extends RuntimeException {}
