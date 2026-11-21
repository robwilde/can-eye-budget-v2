<?php

declare(strict_types=1);

namespace App\Exceptions\Statement;

use RuntimeException;

/** The uploaded file failed a check; the message is written for the person who uploaded it. */
final class StatementFileRejected extends RuntimeException {}
