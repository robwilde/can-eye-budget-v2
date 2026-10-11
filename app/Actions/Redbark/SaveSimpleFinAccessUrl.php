<?php

declare(strict_types=1);

namespace App\Actions\Redbark;

use App\Enums\RedbarkFeedStatus;
use App\Enums\RedbarkTransport;
use App\Exceptions\Redbark\RedbarkRequestException;
use App\Models\RedbarkFeed;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Stores a claimed SimpleFIN access URL (encrypted by the model cast), creating the feed on
 * first save. The REST key is cleared so a feed only ever has one credential kind. Callers
 * claim the token and dispatch the sync themselves.
 */
final class SaveSimpleFinAccessUrl
{
    /**
     * @throws RedbarkRequestException when a REST feed already has linked accounts
     */
    public function handle(User $user, #[SensitiveParameter] string $accessUrl): RedbarkFeed
    {
        return DB::transaction(function () use ($user, $accessUrl): RedbarkFeed {
            RedbarkFeed::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?->prepareTransportSwitch(RedbarkTransport::SimpleFin);

            return RedbarkFeed::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'transport' => RedbarkTransport::SimpleFin,
                    'simplefin_access_url' => $accessUrl,
                    'api_key' => null,
                    'status' => RedbarkFeedStatus::Good,
                    'auth_failure_count' => 0,
                ],
            );
        });
    }
}
