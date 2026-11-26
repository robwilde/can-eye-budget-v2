<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantTool;
use App\Models\User;
use App\Services\NetWorthService;
use Carbon\CarbonImmutable;

final class GetNetWorthHistory implements AssistantTool
{
    use ReadsUserData;

    public const int MAX_POINTS = 400;

    public function __construct(private readonly NetWorthService $netWorth) {}

    public function name(): string
    {
        return 'get_net_worth_history';
    }

    public function description(): string
    {
        return "The user's end-of-day net worth per day, in integer cents. Returns at most 400 days ending at the 'to' date (default today); omit the dates for the last 90 days.";
    }

    public function requiresPreview(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD.'],
                'to' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD.'],
            ],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $valid = $this->validated($arguments, [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...(is_string($arguments['from'] ?? null) ? ['after_or_equal:from'] : [])],
        ]);

        if (is_string($valid)) {
            return ['error' => $valid];
        }

        $to = isset($valid['to']) ? CarbonImmutable::parse($valid['to'])->startOfDay() : $user->today()->startOfDay();
        $from = isset($valid['from']) ? CarbonImmutable::parse($valid['from'])->startOfDay() : $to->subDays(89);
        $earliest = $to->subDays(self::MAX_POINTS - 1);
        $truncated = $from->lessThan($earliest);

        if ($truncated) {
            $from = $earliest;
        }

        $points = [];

        foreach ($this->netWorth->series($user, $from, $to, flatBeforeHistory: true) as $day => $total) {
            $points[] = ['date' => $day, 'net_worth' => $total];
        }

        return ['currency' => $user->base_currency, 'points' => $points, 'truncated' => $truncated];
    }
}
