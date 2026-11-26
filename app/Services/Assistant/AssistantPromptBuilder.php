<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\InstanceSettings;

final readonly class AssistantPromptBuilder
{
    public const int SMALL_CONTEXT_WINDOW = 16_000;

    public function __construct(private InstanceSettings $settings) {}

    /**
     * The system prompt: today's date in the user's timezone, their currency, and their accounts and
     * categories, listed by name or, above the configured limit or in a small context window, as counts.
     */
    public function build(User $user): string
    {
        $limit = config()->integer('services.ai.max_listed', 40);
        $collapse = config()->integer('services.ai.context_window', 128_000) < self::SMALL_CONTEXT_WINDOW;

        $accounts = Account::query()->where('user_id', $user->id)->orderBy('name')->pluck('name')->all();
        $categories = Category::query()->where('user_id', $user->id)->where('is_hidden', false)->orderBy('name')->pluck('name')->all();

        return implode("\n", [
            ...$this->instructions(),
            'Money is stored in integer cents; show amounts to the user in dollars and cents in their currency.',
            'Today is '.$user->today()->format('l j F Y').' ('.$user->resolvedTimezone().').',
            'The user\'s base currency is '.$user->base_currency.'.',
            $this->section('accounts', $accounts, $limit, $collapse),
            $this->section('categories', $categories, $limit, $collapse),
        ]);
    }

    /**
     * The standing instructions: an admin's override when one is saved, otherwise the built-in ones. The lines about the
     * user's own context always follow.
     *
     * @return list<string>
     */
    private function instructions(): array
    {
        $override = $this->settings->systemPrompt();

        if ($override !== null) {
            return [$override];
        }

        return [
            'You are a careful budgeting assistant inside a personal finance app. Answer only from the user\'s own data and say so when you do not know.',
            'Never mention, name or describe the tools or functions you can call; just use them and answer in plain language.',
        ];
    }

    /**
     * @param  list<string>  $names
     */
    private function section(string $label, array $names, int $limit, bool $collapse): string
    {
        if ($names === []) {
            return 'The user has no '.$label.'.';
        }

        if ($collapse || count($names) > $limit) {
            return 'The user has '.count($names).' '.$label.'.';
        }

        return 'The user\'s '.$label.': '.implode(', ', $names).'.';
    }
}
