<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatementFileType;
use App\Enums\StatementReviewStatus;
use App\Models\Account;
use App\Models\Statement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Statement>
 */
final class StatementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'review_status' => StatementReviewStatus::Unmatched,
            'content_hash' => hash('sha256', fake()->uuid()),
            'original_filename' => 'statement.pdf',
            'stored_path' => 'statements/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => fake()->numberBetween(1_000, 500_000),
            'file_type' => StatementFileType::Pdf,
        ];
    }

    public function unmatched(): self
    {
        return $this->state(fn (array $attributes): array => [
            'account_id' => null,
            'review_status' => StatementReviewStatus::Unmatched,
        ]);
    }

    public function linked(): self
    {
        return $this->state(fn (): array => [
            'account_id' => fn (array $attributes): int => Account::factory()->create(['user_id' => $attributes['user_id']])->id,
            'review_status' => StatementReviewStatus::Linked,
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn (array $attributes): array => [
            'account_id' => null,
            'review_status' => StatementReviewStatus::Rejected,
        ]);
    }
}
