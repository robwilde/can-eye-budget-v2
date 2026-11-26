<?php

declare(strict_types=1);

namespace App\Enums;

enum LlmOperation: string
{
    case Chat = 'chat';
    case Title = 'title';
}
