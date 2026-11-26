<?php

declare(strict_types=1);

namespace App\Enums;

enum ChatMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
