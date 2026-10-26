<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionAttachment>
 */
final class TransactionAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'disk' => 'local',
            'path' => 'transaction-attachments/'.fake()->uuid().'.pdf',
            'original_name' => 'receipt.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1000, 500000),
        ];
    }
}
