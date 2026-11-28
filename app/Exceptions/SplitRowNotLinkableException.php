<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A split transaction or one of its lines never takes part in a transfer, so no leg is created for it. */
final class SplitRowNotLinkableException extends TransferLinkRefusedException {}
