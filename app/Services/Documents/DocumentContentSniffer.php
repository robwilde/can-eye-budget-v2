<?php

declare(strict_types=1);

namespace App\Services\Documents;

use ZipArchive;

/**
 * Checks that a document's bytes are what its extension says, the way the statement vault does
 * for statements. An executable, or content of another kind, does not pass.
 */
final class DocumentContentSniffer
{
    private const array OOXML = ['docx' => 'word/', 'pptx' => 'ppt/', 'xlsx' => 'xl/'];

    private const array OLE = ['doc', 'ppt', 'xls'];

    public function matches(string $extension, string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 4096);
        fclose($handle);

        if (str_starts_with($head, 'MZ') || str_starts_with($head, "\x7FELF") || str_starts_with($head, '#!')) {
            return false;
        }

        $extension = mb_strtolower($extension);

        return match (true) {
            isset(self::OOXML[$extension]) => str_starts_with($head, "PK\x03\x04") && $this->hasEntry($path, self::OOXML[$extension]),
            in_array($extension, self::OLE, true) => str_starts_with($head, "\xD0\xCF\x11\xE0"),
            $extension === 'pdf' => str_contains(mb_substr($head, 0, 1024, '8bit'), '%PDF-'),
            default => ! str_contains($head, "\0") && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $head) !== 1,
        };
    }

    private function hasEntry(string $path, string $prefix): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        $found = false;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            if (str_starts_with((string) $zip->getNameIndex($index), $prefix)) {
                $found = true;

                break;
            }
        }

        $zip->close();

        return $found;
    }
}
