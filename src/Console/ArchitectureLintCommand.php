<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class ArchitectureLintCommand implements Command
{
    /**
     * @var list<string>
     */
    private const DEFAULT_SCAN_DIRECTORIES = ['app', 'Modules'];

    /**
     * @var list<string>
     */
    private const IGNORED_PATH_SEGMENTS = [
        '/Console/',
        '/database/',
        '/Views/',
        '/resources/views/',
    ];

    /**
     * @var array<string, string>
     */
    private const FORBIDDEN_PATTERNS = [
        'DB::table' => '/\bDB::table\s*\(/',
        'DB::raw' => '/\bDB::raw\s*\(/',
        'DB::query' => '/\bDB::query\s*\(/',
        'DB::select' => '/\bDB::select\s*\(/',
        'Database::query' => '/(?:\bDatabase::query|\$(?:this->)?(?:database|db)\s*->query)\s*\(/',
        'Database::raw' => '/(?:\bDatabase::raw|\$(?:this->)?(?:database|db)\s*->raw)\s*\(/',
        'Database::statement' => '/(?:\bDatabase::statement|\$(?:this->)?(?:database|db)\s*->statement)\s*\(/',
        'Database::firstResult' => '/(?:\bDatabase::firstResult|\$(?:this->)?(?:database|db)\s*->firstResult)\s*\(/',
    ];

    /** @var resource */
    private mixed $stdout;

    /** @var resource */
    private mixed $stderr;

    public function __construct(
        private readonly string $projectRoot,
        mixed $stdout = null,
        mixed $stderr = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    public function name(): string
    {
        return 'lint:architecture';
    }

    public function description(): string
    {
        return 'Scan app and module PHP code for forbidden direct database usage.';
    }

    /**
     * @param list<string> $arguments
     */
    public function handle(array $arguments = []): int
    {
        $paths = $this->resolveScanPaths($arguments);
        $violations = [];

        foreach ($paths as $path) {
            $violations = [...$violations, ...$this->scanPath($path)];
        }

        if ($violations === []) {
            fwrite($this->stdout, "No architecture violations found.\n");

            return 0;
        }

        fwrite($this->stderr, "Architecture violations found:\n");

        foreach ($violations as $violation) {
            fwrite(
                $this->stderr,
                sprintf(
                    "  %s:%d matches %s. Move single-table access to a Model or complex reads to a Query.\n",
                    $violation['file'],
                    $violation['line'],
                    $violation['pattern'],
                ),
            );
        }

        return 1;
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function resolveScanPaths(array $arguments): array
    {
        $paths = [];

        foreach ($arguments as $argument) {
            if (! is_string($argument) || trim($argument) === '') {
                continue;
            }

            $paths[] = $this->normalizePath($argument);
        }

        if ($paths !== []) {
            return $paths;
        }

        return array_map($this->normalizePath(...), self::DEFAULT_SCAN_DIRECTORIES);
    }

    private function normalizePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($this->projectRoot, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @return list<array{file: string, line: int, pattern: string}>
     */
    private function scanPath(string $path): array
    {
        if (is_file($path)) {
            return $this->isPhpFile($path) ? $this->scanFile($path) : [];
        }

        if (! is_dir($path)) {
            return [];
        }

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }

            $pathname = $file->getPathname();

            if (! $file->isFile() || ! $this->isPhpFile($pathname) || $this->shouldIgnorePath($pathname)) {
                continue;
            }

            $violations = [...$violations, ...$this->scanFile($pathname)];
        }

        return $violations;
    }

    private function isPhpFile(string $path): bool
    {
        return str_ends_with($path, '.php');
    }

    private function shouldIgnorePath(string $path): bool
    {
        foreach (self::IGNORED_PATH_SEGMENTS as $segment) {
            if (str_contains($path, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{file: string, line: int, pattern: string}>
     */
    private function scanFile(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $violations = [];

        foreach ($lines as $index => $line) {
            foreach (self::FORBIDDEN_PATTERNS as $pattern => $regex) {
                if (! preg_match($regex, $line)) {
                    continue;
                }

                $violations[] = [
                    'file' => $file,
                    'line' => $index + 1,
                    'pattern' => $pattern,
                ];
            }
        }

        return $violations;
    }
}
