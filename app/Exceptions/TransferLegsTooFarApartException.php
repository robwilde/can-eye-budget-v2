<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The two legs of a transfer are further apart than a confirmed transfer allows, so nothing was linked. */
final class TransferLegsTooFarApartException extends TransferLinkRefusedException {}
