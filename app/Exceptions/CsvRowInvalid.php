<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/** A CSV cell does not fit the chosen format. */
final class CsvRowInvalid extends InvalidArgumentException {}
