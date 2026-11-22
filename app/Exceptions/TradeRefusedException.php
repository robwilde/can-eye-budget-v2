<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A trade or investment cash movement was refused; nothing was written. */
final class TradeRefusedException extends RuntimeException {}
