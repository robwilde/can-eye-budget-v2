<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PdfImportStatus;
use App\Enums\StatementDocumentType;
use App\Models\PdfImport;
use App\Models\Statement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PdfImport>
 */
final class PdfImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'statement_id' => fn (array $attributes): int => Statement::factory()->create(['user_id' => $attributes['user_id']])->id,
            'status' => PdfImportStatus::Pending,
        ];
    }

    public function ready(): self
    {
        return $this->state(fn (): array => [
            'status' => PdfImportStatus::Ready,
            'document_type' => StatementDocumentType::BankStatement,
            'date_format' => 'd/m/Y',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    public function failed(string $message = 'The AI provider could not process this document.'): self
    {
        return $this->state(fn (): array => [
            'status' => PdfImportStatus::Failed,
            'failure_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
