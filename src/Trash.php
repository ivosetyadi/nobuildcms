<?php

declare(strict_types=1);

namespace NoBuildCMS;

/**
 * Soft-delete store. Deleted records are captured here so they can be
 * restored, or purged permanently.
 */
final class Trash
{
    public function __construct(private Store $store)
    {
    }

    /** Move a record into the trash. */
    public function capture(string $type, array $record, ?array $actor = null): array
    {
        $items = $this->store->read('trash');
        $entry = [
            'trash_id' => 't_' . substr(bin2hex(random_bytes(6)), 0, 12),
            'type' => $type,
            'title' => $record['title'] ?? ($record['name'] ?? $record['id'] ?? '—'),
            'record' => $record,
            'deleted_at' => date('c'),
            'deleted_by' => $actor['email'] ?? 'system',
        ];
        $items[] = $entry;
        $this->store->write('trash', $items);

        return $entry;
    }

    public function all(): array
    {
        return array_reverse($this->store->read('trash'));
    }

    /** Restore a trashed record back into its collection. */
    public function restore(string $trashId): ?array
    {
        $items = $this->store->read('trash');
        $found = null;
        $rest = [];
        foreach ($items as $e) {
            if (($e['trash_id'] ?? null) === $trashId) {
                $found = $e;
            } else {
                $rest[] = $e;
            }
        }
        if ($found) {
            $this->store->save($found['type'], $found['record']);
            $this->store->write('trash', $rest);
        }

        return $found;
    }

    /** Permanently remove a trashed record. */
    public function purge(string $trashId): void
    {
        $items = array_values(array_filter(
            $this->store->read('trash'),
            fn ($e) => ($e['trash_id'] ?? null) !== $trashId
        ));
        $this->store->write('trash', $items);
    }
}
