<?php

declare(strict_types=1);

namespace Wayfinder\View;

use Wayfinder\Http\Request;
use Wayfinder\Http\Response;

final class View
{
    /**
     * @var array<string, string>
     */
    private array $paths;

    public function __construct(
        string $basePath,
        private readonly string $extension = 'php',
    ) {
        $this->paths = ['' => $basePath];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        $viewPath = $this->resolvePath($name);

        if (! is_file($viewPath)) {
            throw new \RuntimeException(sprintf('View [%s] was not found at [%s].', $name, $viewPath));
        }

        if (($data['request'] ?? null) instanceof Request) {
            $data['form'] = new FormState(
                $data['request'],
                is_string($data['csrfToken'] ?? null) ? $data['csrfToken'] : null,
            );
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewPath;

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function response(string $name, array $data = [], int $status = 200, array $headers = []): Response
    {
        return Response::html($this->render($name, $data), $status, $headers);
    }

    public function addPath(string $path, ?string $namespace = null): self
    {
        $this->paths[$namespace === null ? '' : strtolower($namespace)] = $path;

        return $this;
    }

    private function resolvePath(string $name): string
    {
        $namespace = '';
        $view = $name;

        if (str_contains($name, '::')) {
            [$namespace, $view] = explode('::', $name, 2);
            $namespace = strtolower(trim($namespace));
        }

        $basePath = $this->paths[$namespace] ?? null;

        if (! is_string($basePath)) {
            throw new \RuntimeException(sprintf('View namespace [%s] is not registered.', $namespace));
        }

        $relativePath = str_replace('.', '/', trim($view, '.'));

        return rtrim($basePath, '/') . '/' . $relativePath . '.' . $this->extension;
    }
}
