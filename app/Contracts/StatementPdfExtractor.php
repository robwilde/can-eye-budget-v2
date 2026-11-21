<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ExtractedStatement;
use App\Exceptions\Statement\StatementExtractionFailed;

interface StatementPdfExtractor
{
    /**
     * Reads a statement PDF. The whole document leaves the app.
     *
     * @throws StatementExtractionFailed with a message safe to show the user
     */
    public function extract(string $pdfBytes): ExtractedStatement;
}
