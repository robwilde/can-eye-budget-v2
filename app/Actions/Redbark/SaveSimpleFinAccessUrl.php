<?php

declare(strict_types=1);

namespace App\Actions\Redbark;

use App\Enums\RedbarkFeedStatus;
use App\Enums\RedbarkTransport;
use App\Models\RedbarkFeed;
use App\Models\User;
use SensitiveParameter;

/**
 * Stores a claimed SimpleFIN access URL (encrypted by the model cast) on the user's SimpleFIN
 * feed, creating the feed on first save. Callers claim the token and dispatch the sync
 * themselves.
 */
final class SaveSimpleFinAccessUrl
{
    public function handle(User $user, #[SensitiveParameter] string $accessUrl): RedbarkFeed
    {
        return RedbarkFeed::query()->updateOrCreate(
            ['user_id' => $user->id, 'transport' => RedbarkTransport::SimpleFin],
            [
                'simplefin_access_url' => $accessUrl,
                'status' => RedbarkFeedStatus::Good,
                'auth_failure_count' => 0,
            ],
        );
    }
}
