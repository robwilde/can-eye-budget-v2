<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A category merge or delete was refused; nothing was written. */
final class CategoryMergeRefusedException extends RuntimeException {}
