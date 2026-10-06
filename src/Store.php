<?php

declare(strict_types=1);

namespace NoBuildCMS;

/**
 * Flat-file JSON store. No database.
 *
 * Collections (pages/posts/products/users) are JSON arrays of records.
 * Singletons (settings) are JSON objects.
 */
final class Store
{
    public function __construct(private string $dir)
    {
    }

    private function path(string $name): string
    {
        return $this->dir . '/' . basename($name) . '.json';
    }

    /** Read a JSON file as an array (list or object). */
    public function read(string $name): array
    {
        $p = $this->path($name);
        if (!is_file($p)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($p), true);

        return is_array($json) ? $json : [];
    }

    /** Write data back as pretty JSON, atomically. */
    public function write(string $name, array $data): void
    {
        $p = $this->path($name);
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $tmp = $p . '.tmp';
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $p);
    }

    /** All records of a content type. */
    public function all(string $type): array
    {
        return $this->read($type);
    }

    public function find(string $type, string $slug): ?array
    {
        foreach ($this->all($type) as $r) {
            if (($r['slug'] ?? null) === $slug) {
                return $r;
            }
        }

        return null;
    }

    public function findById(string $type, string $id): ?array
    {
        foreach ($this->all($type) as $r) {
            if (($r['id'] ?? null) === $id) {
                return $r;
            }
        }

        return null;
    }

    /** Upsert a record by id. Returns the saved record. */
    public function save(string $type, array $record): array
    {
        $rows = $this->all($type);
        $id = $record['id'] ?? null;
        $now = date('c');
        $found = false;

        foreach ($rows as $i => $r) {
            if ($id !== null && ($r['id'] ?? null) === $id) {
                $record['created_at'] = $r['created_at'] ?? $now;
                $record['updated_at'] = $now;
                $rows[$i] = array_merge($r, $record);
                $record = $rows[$i];
                $found = true;
                break;
            }
        }

        if (!$found) {
            $record['id'] = $id ?: ($type[0] ?? 'r') . '_' . substr(bin2hex(random_bytes(6)), 0, 10);
            $record['created_at'] = $now;
            $record['updated_at'] = $now;
            $rows[] = $record;
        }

        $this->write($type, $rows);

        return $record;
    }

    public function delete(string $type, string $id): void
    {
        $rows = array_values(array_filter(
            $this->all($type),
            fn ($r) => ($r['id'] ?? null) !== $id
        ));
        $this->write($type, $rows);
    }

    /** Monotonic-ish version used by the SSE live-reload channel. */
    public function version(): string
    {
        $max = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $max = max($max, (int) filemtime($f));
        }

        return (string) $max;
    }
}
