<?php

declare(strict_types=1);

namespace App\Support;

final class PreviewNotice
{
    public const string SESSION_KEY = 'preview_notice';

    public static function message(): string
    {
        return __('This feature is in preview. Turn on preview features in your preferences to use it.');
    }
}
