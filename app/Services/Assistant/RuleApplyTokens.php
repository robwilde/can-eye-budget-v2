<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\User;
use App\Models\UserRule;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived tokens that bind "apply this rule to my history" to what the user was shown: the token carries a hash of
 * the rule definition and the number of transactions the preview would change, is signed with the app key, and expires
 * after 30 minutes. A token stops working as soon as the rule is edited or the match count moves.
 */
final class RuleApplyTokens
{
    public const int LIFETIME_MINUTES = 30;

    public function issue(User $user, UserRule $rule, int $matchCount): string
    {
        $payload = $this->encode([
            'u' => $user->id,
            'r' => $rule->id,
            'h' => $this->definitionHash($rule),
            'c' => $matchCount,
            'e' => now()->addMinutes(self::LIFETIME_MINUTES)->getTimestamp(),
        ]);

        return $payload.'.'.$this->sign($payload);
    }

    public function accepts(string $token, User $user, UserRule $rule, int $matchCount): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2 || ! hash_equals($this->sign($parts[0]), $parts[1])) {
            return false;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);

        return is_array($claims)
            && ($claims['u'] ?? null) === $user->id
            && ($claims['r'] ?? null) === $rule->id
            && ($claims['h'] ?? null) === $this->definitionHash($rule)
            && ($claims['c'] ?? null) === $matchCount
            && is_int($claims['e'] ?? null)
            && $claims['e'] >= now()->getTimestamp();
    }

    /**
     * Marks the token as used; false when it was used before, so one preview can authorise one apply.
     */
    public function consume(string $token): bool
    {
        return Cache::add('assistant:rule-apply-token:'.hash('sha256', $token), true, now()->addMinutes(self::LIFETIME_MINUTES + 1));
    }

    private function definitionHash(UserRule $rule): string
    {
        return hash('sha256', json_encode([
            $rule->triggers,
            $rule->actions,
            $rule->strict_mode,
            $rule->effective_from?->toDateString(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, int|string>  $claims
     */
    private function encode(array $claims): string
    {
        return mb_rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, config()->string('app.key'));
    }
}
