<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A document upload was refused; the message is safe to show the user. */
final class DocumentRefused extends RuntimeException {}
