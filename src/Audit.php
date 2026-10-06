<?php

declare(strict_types=1);

namespace NoBuildCMS;

/**
 * Append-only audit log. Entries are write-once — the dashboard can read and
 * export them, but there is no edit or delete path.
 */
final class Audit
{
    public function __construct(private Store $store)
    {
    }

    public function log(string $action, string $resource = '', array $meta = [], ?array $actor = null): void
    {
        $entries = $this->store->read('audit');
        $entries[] = [
            'id' => 'a_' . substr(bin2hex(random_bytes(6)), 0, 12),
            'ts' => date('c'),
            'actor' => $actor['email'] ?? 'system',
            'actor_name' => $actor['name'] ?? 'System',
            'action' => $action,
            'resource' => $resource,
            'meta' => $meta,
        ];
        // Keep the log bounded so the JSON file can't grow without limit.
        if (count($entries) > 2000) {
            $entries = array_slice($entries, -2000);
        }
        $this->store->write('audit', $entries);
    }

    /** Most recent entries first. */
    public function recent(int $limit = 500): array
    {
        return array_slice(array_reverse($this->store->read('audit')), 0, $limit);
    }
}
