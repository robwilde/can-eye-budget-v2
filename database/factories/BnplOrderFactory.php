<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BnplOrderStatus;
use App\Enums\BnplProvider;
use App\Enums\RecurrenceFrequency;
use App\Models\Account;
use App\Models\BnplOrder;
use App\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BnplOrder>
 */
final class BnplOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstDue = CarbonImmutable::today()->addWeeks(2);

        return [
            'user_id' => User::factory(),
            'provider' => BnplProvider::Afterpay,
            'retailer' => fake()->company(),
            'order_ref' => (string) fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'total' => 7445,
            'instalment_amount' => 1861,
            'instalment_count' => 4,
            'first_due_date' => $firstDue,
            'last_due_date' => $firstDue->addWeeks(6),
            'frequency' => RecurrenceFrequency::Every2Weeks,
            'account_id' => fn (array $attributes) => Account::factory()->create(['user_id' => $attributes['user_id']])->id,
            'category_id' => null,
            'card_last4' => '8357',
            'status' => BnplOrderStatus::PendingReview,
            'review_note' => null,
            'planned_transaction_id' => null,
            'gmail_message_id' => fake()->unique()->uuid().'@afterpay.com',
            'subject' => 'Thank you for your Afterpay order',
            'email_date' => CarbonImmutable::now()->subDay(),
            'snippet' => 'Petbarn · $74.45 · 4 payments of $18.61',
            'gmail_url' => 'https://mail.google.com/mail/u/0/#search/rfc822msgid:'.fake()->uuid(),
            'parsed_payload' => null,
            'reviewed_at' => null,
        ];
    }

    public function autoApproved(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => BnplOrderStatus::AutoApproved,
            'category_id' => Category::factory(),
            'reviewed_at' => CarbonImmutable::now(),
        ]);
    }

    public function approved(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => BnplOrderStatus::Approved,
            'category_id' => Category::factory(),
            'reviewed_at' => CarbonImmutable::now(),
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => BnplOrderStatus::Rejected,
            'reviewed_at' => CarbonImmutable::now(),
        ]);
    }

    public function settled(): self
    {
        $firstDue = CarbonImmutable::today()->subWeeks(8);

        return $this->state(fn (array $attributes) => [
            'first_due_date' => $firstDue,
            'last_due_date' => $firstDue->addWeeks(6),
        ]);
    }
}
