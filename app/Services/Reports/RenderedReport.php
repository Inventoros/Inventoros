<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * A report rendered to bytes in one export format, ready to download or to
 * attach to an email.
 */
final class RenderedReport
{
    public function __construct(
        public readonly string $content,
        public readonly string $mimeType,
        public readonly string $filename,
    ) {}
}
