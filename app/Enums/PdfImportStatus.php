<?php

declare(strict_types=1);

namespace App\Enums;

enum PdfImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Published = 'published';
    case Reverted = 'reverted';
    case Failed = 'failed';
}
