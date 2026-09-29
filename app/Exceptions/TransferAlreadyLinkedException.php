<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The row was paired (or suggested) with something else before this link could be written. */
final class TransferAlreadyLinkedException extends TransferLinkRefusedException {}
