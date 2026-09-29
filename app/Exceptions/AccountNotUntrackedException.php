<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The counterpart account is not (or no longer) an untracked account, so no mirror leg may be created. */
final class AccountNotUntrackedException extends TransferLinkRefusedException {}
