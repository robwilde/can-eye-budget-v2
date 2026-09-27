<?php

declare(strict_types=1);

namespace App\Actions\Redbark;

use App\Enums\RedbarkFeedStatus;
use App\Models\RedbarkFeed;
use App\Models\User;

/**
 * Stores a user's Redbark API key (encrypted by the model cast), creating the feed on
 * first save. Shared by the providers settings panel and the onboarding page; callers
 * validate the key and dispatch the sync themselves.
 */
final class SaveRedbarkApiKey
{
    public function handle(User $user, string $apiKey): RedbarkFeed
    {
        // Resetting the status is what lets a rotated key clear a RequiresUpdate state.
        return RedbarkFeed::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['api_key' => $apiKey, 'status' => RedbarkFeedStatus::Good, 'auth_failure_count' => 0],
        );
    }
}
