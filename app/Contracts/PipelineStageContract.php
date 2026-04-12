<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\PipelineContext;
use App\DTOs\StageResult;

interface PipelineStageContract
{
    public function key(): string;

    public function label(): string;

    public function shouldRun(PipelineContext $context): bool;

    public function execute(PipelineContext $context): StageResult;
}
