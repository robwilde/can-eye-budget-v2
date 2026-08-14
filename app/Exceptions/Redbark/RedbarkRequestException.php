<?php

declare(strict_types=1);

namespace App\Exceptions\Redbark;

/**
 * Every Redbark failure that is not an authentication, rate-limit or server error:
 * bad_request (400 and 410), not_found (404), truncated, too_many_pages, unknown.
 */
final class RedbarkRequestException extends RedbarkException {}
