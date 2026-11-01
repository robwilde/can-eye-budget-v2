<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A split, split edit or split undo was refused; nothing was written. */
final class TransactionSplitRefusedException extends RuntimeException {}
