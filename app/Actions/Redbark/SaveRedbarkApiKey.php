<?php

declare(strict_types=1);

namespace App\Actions\Redbark;

use App\Enums\RedbarkFeedStatus;
use App\Enums\RedbarkTransport;
use App\Exceptions\Redbark\RedbarkRequestException;
use App\Models\RedbarkFeed;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stores a user's Redbark API key (encrypted by the model cast), creating the feed on
 * first save. Shared by the providers settings panel and the onboarding page; callers
 * validate the key and dispatch the sync themselves.
 */
final class SaveRedbarkApiKey
{
    /**
     * @throws RedbarkRequestException when a SimpleFIN feed already has linked accounts
     */
    public function handle(User $user, string $apiKey): RedbarkFeed
    {
        return DB::transaction(function () use ($user, $apiKey): RedbarkFeed {
            RedbarkFeed::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?->prepareTransportSwitch(RedbarkTransport::Rest);

            // Resetting the status is what lets a rotated key clear a RequiresUpdate state.
            return RedbarkFeed::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'transport' => RedbarkTransport::Rest,
                    'api_key' => $apiKey,
                    'simplefin_access_url' => null,
                    'status' => RedbarkFeedStatus::Good,
                    'auth_failure_count' => 0,
                ],
            );
        });
    }
}
