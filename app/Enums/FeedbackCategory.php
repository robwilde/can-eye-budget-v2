<?php

declare(strict_types=1);

namespace App\Enums;

enum FeedbackCategory: string
{
    case Bug = 'bug';
    case FeatureRequest = 'feature-request';
    case Question = 'question';

    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Bug',
            self::FeatureRequest => 'Feature Request',
            self::Question => 'Question',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Bug => "\u{1F41B}",
            self::FeatureRequest => "\u{1F4A1}",
            self::Question => "\u{2753}",
        };
    }

    public function githubLabel(): string
    {
        return $this->value;
    }
}
