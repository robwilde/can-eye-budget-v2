<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
final class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'filename' => 'notes.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'byte_size' => fake()->numberBetween(1_000, 500_000),
            'stored_path' => 'documents/'.fake()->uuid().'.docx',
            'content_hash' => hash('sha256', fake()->uuid()),
            'status' => DocumentStatus::Pending,
        ];
    }
}
