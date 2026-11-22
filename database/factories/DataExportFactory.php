<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataExport>
 */
final class DataExportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => DataExportStatus::Pending,
        ];
    }

    public function processingSince(DateTimeInterface $startedAt): self
    {
        return $this->state(fn (): array => ['status' => DataExportStatus::Processing, 'started_at' => $startedAt]);
    }
}
