<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Recording the transactions a rule already matches took longer than the time allowed. */
final class RuleBaselineTimedOutException extends RuntimeException {}
