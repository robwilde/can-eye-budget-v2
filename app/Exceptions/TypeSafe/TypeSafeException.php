<?php

declare(strict_types=1);

namespace App\Exceptions\TypeSafe;

use RuntimeException;

/** A TypeSafe System One call failed, or answered with something other than a well-formed choice. */
final class TypeSafeException extends RuntimeException {}
