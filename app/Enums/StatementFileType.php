<?php

declare(strict_types=1);

namespace App\Enums;

enum StatementFileType: string
{
    case Pdf = 'pdf';
    case Csv = 'csv';
    case Xlsx = 'xlsx';
}
