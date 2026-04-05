<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\FeedbackCategory;
use Spatie\LaravelData\Dto;

final class FeedbackSubmission extends Dto
{
    public function __construct(
        public readonly FeedbackCategory $category,
        public readonly string $description,
        public readonly ?string $screenshotPath,
        public readonly string $pageUrl,
        public readonly string $userAgent,
        public readonly string $viewport,
        public readonly string $userName,
        public readonly string $userEmail,
    ) {}
}
