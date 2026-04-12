<?php

declare(strict_types=1);

namespace App\Enums;

enum PipelineRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case PartialFailure = 'partial-failure';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::PartialFailure => 'Partial Failure',
            self::Failed => 'Failed',
        };
    }
}
