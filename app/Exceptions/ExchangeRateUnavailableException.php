<?php

declare(strict_types=1);

namespace App\Exceptions;

/** No exchange rate could be found for a cross-currency transfer or leg; nothing was written. */
final class ExchangeRateUnavailableException extends TransferLinkRefusedException {}
