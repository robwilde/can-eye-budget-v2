<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A holding remap, reset, cost basis or delete was refused; nothing was changed. */
final class HoldingRefusedException extends RuntimeException {}
