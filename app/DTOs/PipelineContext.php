<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\PipelineRun;
use App\Models\User;
use Spatie\LaravelData\Dto;

final class PipelineContext extends Dto
{
    public function __construct(
        public readonly User $user,
        public readonly PipelineRun $pipelineRun,
        public readonly bool $isFirstSync,
    ) {}
}
