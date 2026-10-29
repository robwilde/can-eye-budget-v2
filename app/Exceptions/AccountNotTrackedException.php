<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The account chosen for a missing transfer leg is not a tracked account, so nothing was created. */
final class AccountNotTrackedException extends TransferLinkRefusedException {}
