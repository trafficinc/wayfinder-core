<?php

declare(strict_types=1);

namespace Wayfinder\Session;

use Wayfinder\Database\Database;

final class DatabaseSessionStore implements SessionStore
{
    public function __construct(
        private readonly Database $database,
        private readonly string $table = 'sessions',
    ) {
    }

    public function load(string $id): Session
    {
        $row = $this->database->firstResult(
            sprintf('SELECT payload FROM %s WHERE id = ? LIMIT 1', $this->database->qualifyIdentifier($this->table)),
            [$id],
        );

        if (! is_array($row) || ! is_string($row['payload'] ?? null)) {
            return new Session($id);
        }

        $decoded = json_decode($row['payload'], true);

        if (! is_array($decoded)) {
            return new Session($id);
        }

        return new Session($id, $decoded, true);
    }

    public function save(Session $session): void
    {
        $payload = json_encode($session->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new \RuntimeException('Unable to encode session payload.');
        }

        $table = $this->database->qualifyIdentifier($this->table);
        $previousId = $session->previousId();

        $this->database->transaction(function () use ($session, $payload, $table, $previousId): void {
            if (is_string($previousId) && $previousId !== $session->id()) {
                $this->delete($previousId);
            }

            $exists = $this->database->firstResult(
                sprintf('SELECT id FROM %s WHERE id = ? LIMIT 1', $table),
                [$session->id()],
            );

            if (is_array($exists)) {
                $this->database->statement(
                    sprintf('UPDATE %s SET payload = ?, last_activity = ? WHERE id = ?', $table),
                    [$payload, time(), $session->id()],
                );

                return;
            }

            $this->database->statement(
                sprintf('INSERT INTO %s (id, payload, last_activity) VALUES (?, ?, ?)', $table),
                [$session->id(), $payload, time()],
            );
        });

        $session->syncAfterSave();
    }

    public function delete(string $id): void
    {
        $this->database->statement(
            sprintf('DELETE FROM %s WHERE id = ?', $this->database->qualifyIdentifier($this->table)),
            [$id],
        );
    }
}
