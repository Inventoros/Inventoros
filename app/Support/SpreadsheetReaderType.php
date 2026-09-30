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

        return self::BY_MIME[strtolower($mime)]
            ?? throw new RuntimeException('The file is not a CSV, XLSX or XLS spreadsheet.');
    }
}
