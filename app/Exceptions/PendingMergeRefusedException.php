<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A pending transaction could not be merged into the chosen posted one; nothing was written. */
final class PendingMergeRefusedException extends RuntimeException {}
