<?php

declare(strict_types=1);

namespace Wayfinder\Session;

final class FileSessionStore implements SessionStore
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function load(string $id): Session
    {
        $file = $this->pathFor($id);

        if (! is_file($file)) {
            return new Session($id);
        }

        $contents = file_get_contents($file);
        $decoded = is_string($contents) ? json_decode($contents, true) : null;

        if (! is_array($decoded)) {
            return new Session($id);
        }

        return new Session($id, $decoded, true);
    }

    public function save(Session $session): void
    {
        if (! is_dir($this->path) && ! @mkdir($concurrentDirectory = $this->path, 0777, true) && ! is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create session directory [%s].', $this->path));
        }

        $encoded = json_encode($session->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode session payload.');
        }

        $previousId = $session->previousId();

        file_put_contents($this->pathFor($session->id()), $encoded);

        if (is_string($previousId) && $previousId !== $session->id()) {
            $this->delete($previousId);
        }

        $session->syncAfterSave();
    }

    public function delete(string $id): void
    {
        $file = $this->pathFor($id);

        if (is_file($file)) {
            unlink($file);
        }
    }

    private function pathFor(string $id): string
    {
        return rtrim($this->path, '/') . '/' . $id . '.json';
    }
}
