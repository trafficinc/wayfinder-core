<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Concerns;

trait UsesTempDirectory
{
    private string $tempDir;

    protected function setUpTempDirectory(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/wayfinder_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDownTempDirectory(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $path): void
    {
        foreach (glob($path . '/{,.}*', GLOB_BRACE) ?: [] as $item) {
            $base = basename($item);
            if ($base === '.' || $base === '..') {
                continue;
            }
            is_dir($item) ? $this->removeDirectory($item) : unlink($item);
        }
        rmdir($path);
    }
}
