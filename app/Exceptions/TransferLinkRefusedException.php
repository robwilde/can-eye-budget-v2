<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A transfer link was refused; nothing was written. */
abstract class TransferLinkRefusedException extends RuntimeException {}
