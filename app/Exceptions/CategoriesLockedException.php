<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Creating a category was refused because the user has locked their categories. */
final class CategoriesLockedException extends RuntimeException {}
