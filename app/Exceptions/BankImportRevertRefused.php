<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A CSV import could not be reverted; the message is safe to show the user. */
final class BankImportRevertRefused extends RuntimeException {}
