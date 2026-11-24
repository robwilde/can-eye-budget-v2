<?php

declare(strict_types=1);

namespace App\Services\Bills;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The private calendar feed's token. The plain token exists only in the URL handed to the user when it is made;
 * the database keeps a SHA-256 of it (the token is long and random, so no salt or slow hash is needed), a lookup
 * is by that hash followed by a constant-time comparison, and issuing a new one replaces the old at once.
 */
final readonly class CalendarFeedTokens
{
    public const int LENGTH = 40;

    public const string PATTERN = '[A-Za-z0-9]{40}';

    public function issue(User $user): string
    {
        $token = Str::random(self::LENGTH);

        User::query()->whereKey($user->id)->update(['calendar_feed_token_hash' => $this->hash($token)]);

        $user->forceFill(['calendar_feed_token_hash' => $this->hash($token)])->syncOriginal();

        return $token;
    }

    public function find(string $token): ?User
    {
        if (preg_match('/^'.self::PATTERN.'$/D', $token) !== 1) {
            return null;
        }

        $hash = $this->hash($token);
        $user = User::query()->where('calendar_feed_token_hash', $hash)->first();

        return $user instanceof User && hash_equals((string) $user->calendar_feed_token_hash, $hash) ? $user : null;
    }

    public function exists(User $user): bool
    {
        return User::query()->whereKey($user->id)->whereNotNull('calendar_feed_token_hash')->exists();
    }

    public function url(string $token): string
    {
        return route('bills.feed', ['token' => $token]);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
