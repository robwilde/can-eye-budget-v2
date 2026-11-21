<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\Enums\StatementReviewStatus;
use App\Models\Statement;
use App\Support\Currencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StatementDetailsUpdater
{
    private const array EDITABLE = [
        'institution_hint', 'account_name_hint', 'last_four_hint', 'period_start', 'period_end',
        'opening_balance', 'closing_balance', 'currency', 'account_id',
    ];

    public function __construct(private readonly StatementAccountMatcher $matcher) {}

    /**
     * Saves the edited details. A statement with no account that was not rejected is matched
     * again against the owner's accounts.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws ValidationException
     */
    public function update(Statement $statement, array $details): Statement
    {
        $details = array_intersect_key($details, array_flip(self::EDITABLE));

        if (isset($details['currency']) && is_string($details['currency'])) {
            $details['currency'] = mb_strtoupper(mb_trim($details['currency']));
        }

        $checked = $details + array_filter([
            'period_start' => $statement->period_start?->toDateString(),
            'period_end' => $statement->period_end?->toDateString(),
        ]);

        $validated = Validator::make($checked, [
            'institution_hint' => ['nullable', 'string', 'max:255'],
            'account_name_hint' => ['nullable', 'string', 'max:255'],
            'last_four_hint' => ['nullable', 'digits:4'],
            'period_start' => ['nullable', 'date', 'before_or_equal:period_end'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'opening_balance' => ['nullable', 'integer'],
            'closing_balance' => ['nullable', 'integer'],
            'currency' => ['nullable', Rule::in(Currencies::codes())],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $statement->user_id)],
        ])->validate();

        $validated = array_intersect_key($validated, $details);

        return DB::transaction(function () use ($statement, $validated): Statement {
            $statement->fill($validated);

            if (array_key_exists('account_id', $validated) && $validated['account_id'] !== null) {
                $statement->suggested_account_id = null;
                $statement->suggestion_confidence = null;
            }

            $statement->syncReviewStatus();

            if ($statement->account_id === null && $statement->review_status !== StatementReviewStatus::Rejected) {
                $this->matcher->suggest($statement);
            }

            $statement->save();

            return $statement;
        });
    }
}
