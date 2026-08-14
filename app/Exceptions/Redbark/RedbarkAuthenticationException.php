<?php

declare(strict_types=1);

namespace App\Exceptions\Redbark;

/**
 * 401 and 403 only. This is the one SyncRedbarkFeedJob catches to flip the feed to
 * RedbarkFeedStatus::RequiresUpdate — every other failure is per-phase and recoverable.
 */
final class RedbarkAuthenticationException extends RedbarkException {}
