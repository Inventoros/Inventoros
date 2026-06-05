<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Update\FileUpdateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class FileUpdateServiceTest extends TestCase
{
    public function test_replace_files_throws_when_copy_fails(): void
    {
        // First directory ('app'): source exists, dest exists, delete ok,
        // but copyDirectory fails -> must throw.
        File::shouldReceive('exists')->andReturn(true);
        File::shouldReceive('deleteDirectory')->andReturn(true);
        File::shouldReceive('copyDirectory')->andReturn(false);
        File::shouldReceive('copy')->andReturn(true);

        $this->expectException(\RuntimeException::class);

        app(FileUpdateService::class)->replaceFiles('/tmp/source');
    }
}
