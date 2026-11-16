<?php

declare(strict_types=1);

namespace App\Support;

use Maatwebsite\Excel\Excel;
use RuntimeException;

/**
 * Picks the spreadsheet reader for an uploaded import from the file's
 * detected content type, never from the name the client sent. Laravel Excel
 * otherwise chooses the reader from the extension, so a file could be pushed
 * into a different (for example the legacy XLS/OLE) parser than its content
 * warrants.
 */
final class SpreadsheetReaderType
{
    /**
     * @var array<string, string>
     */
    private const BY_MIME = [
        'text/csv' => Excel::CSV,
        'text/plain' => Excel::CSV,
        'application/csv' => Excel::CSV,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => Excel::XLSX,
        'application/zip' => Excel::XLSX,
        'application/vnd.ms-excel' => Excel::XLS,
        'application/x-ole-storage' => Excel::XLS,
        'application/cdfv2' => Excel::XLS,
    ];

    /**
     * @throws RuntimeException When the content is not a supported spreadsheet.
     */
    public static function forPath(string $path): string
    {
        $mime = is_file($path) ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path) : '';

        $type = self::BY_MIME[strtolower($mime)]
            ?? throw new RuntimeException('The file is not a CSV, XLSX or XLS spreadsheet.');

        // An XLSX is a ZIP the reader inflates in memory: refuse a small
        // upload that would expand far beyond what an import needs.
        if ($type === Excel::XLSX) {
            try {
                SafeZipExtractor::inspect($path, [
                    'max_entries' => (int) config('imports.xlsx.max_entries', 1000),
                    'max_bytes' => (int) config('imports.xlsx.max_uncompressed_bytes', 256 * 1024 * 1024),
                    'max_ratio' => (float) config('imports.xlsx.max_compression_ratio', 100),
                    'ratio_min_bytes' => (int) config('imports.xlsx.ratio_min_bytes', 1024 * 1024),
                ]);
            } catch (RuntimeException) {
                throw new RuntimeException('The spreadsheet is too large to import once uncompressed, or it is not a valid XLSX file.');
            }
        }

        return $type;
    }
}
