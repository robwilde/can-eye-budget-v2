<?php

declare(strict_types=1);

namespace App\Exceptions\Statement;

use RuntimeException;

/** A PDF import was not started; the message is safe to show the user. */
final class PdfImportRefused extends RuntimeException {}
