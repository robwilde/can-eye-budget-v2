<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A real imported opposite row exists, so a synthetic hidden-account mirror must not be created. */
final class RealOppositeRowExistsException extends TransferLinkRefusedException {}
