<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Statement;

/**
 * Builds a one-page PDF whose text layer holds the given lines, so the metadata reader
 * runs against a real file instead of a mocked parser.
 */
final class StatementPdfFactory
{
    /**
     * @param  list<string>  $lines
     * @param  list<string>  $secondPage
     * @param  list<string>  $thirdPage
     */
    public static function make(array $lines, array $secondPage = [], array $thirdPage = []): string
    {
        $pages = array_values(array_filter([$lines, $secondPage, $thirdPage], static fn (array $page): bool => $page !== []));
        $objects = [];
        $kids = [];
        $fontId = 3 + count($pages) * 2;

        foreach ($pages as $index => $pageLines) {
            $pageId = 3 + $index * 2;
            $contentId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $stream = "BT /F1 10 Tf 40 780 Td 14 TL\n";

            foreach ($pageLines as $line) {
                $text = mb_convert_encoding($line, 'Windows-1252', 'UTF-8');
                $stream .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text).") '\n";
            }

            $stream .= 'ET';
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Contents {$contentId} 0 R /Resources << /Font << /F1 {$fontId} 0 R >> >> >>";
            $objects[$contentId] = '<< /Length '.mb_strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = mb_strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = mb_strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer
<< /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * A small PDF whose only content stream inflates to far more than the reader may decode.
     */
    public static function inflationBomb(int $megabytes): string
    {
        $context = deflate_init(ZLIB_ENCODING_DEFLATE);
        $stream = '';
        $chunk = str_repeat("\0", 1024 * 1024);

        for ($i = 0; $i < $megabytes; $i++) {
            $stream .= deflate_add($context, $chunk, ZLIB_NO_FLUSH);
        }

        $stream .= deflate_add($context, '', ZLIB_FINISH);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Contents 4 0 R >>',
            4 => '<< /Length '.mb_strlen($stream)." /Filter /FlateDecode >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = mb_strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = mb_strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
