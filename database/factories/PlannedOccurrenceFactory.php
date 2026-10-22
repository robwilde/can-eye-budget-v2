<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OccurrenceStatus;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedOccurrence>
 */
final class PlannedOccurrenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d');

        return [
            'planned_transaction_id' => PlannedTransaction::factory(),
            'original_date' => $date,
            'due_date' => $date,
            'status' => OccurrenceStatus::Scheduled,
        ];
    }

    public function paid(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => OccurrenceStatus::Paid,
            'closed_at' => now(),
        ]);
    }

    public function skipped(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => OccurrenceStatus::Skipped,
            'closed_at' => now(),
            'closed_manually' => true,
        ]);
    }

    public function missed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => OccurrenceStatus::Missed,
            'closed_at' => now(),
            'closed_manually' => true,
        ]);
    }

    public function snoozed(): self
    {
        return $this->state(fn (array $attributes) => [
            'snoozed_until' => now()->addDays(3)->toDateString(),
        ]);
    }
}
