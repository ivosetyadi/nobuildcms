<?php

declare(strict_types=1);

namespace NoBuildCMS;

/** Lightweight visitor presence tracking in data/visitors.json. */
final class Visitors
{
    public function __construct(private Store $store)
    {
    }

    /** Record that a visitor is active now. */
    public function touch(array $visitor): void
    {
        $id = $visitor['id'] ?? null;
        if (!$id) {
            return;
        }
        $all = $this->store->read('visitors');
        $now = date('c');
        $found = false;
        foreach ($all as &$v) {
            if (($v['id'] ?? null) === $id) {
                $v['last_seen'] = $now;
                $v['visits'] = ($v['visits'] ?? 0) + 1;
                $v['name'] = $visitor['name'] ?? ($v['name'] ?? 'Guest');
                $found = true;
                break;
            }
        }
        unset($v);
        if (!$found) {
            $all[] = ['id' => $id, 'name' => $visitor['name'] ?? 'Guest', 'first_seen' => $now, 'last_seen' => $now, 'visits' => 1];
        }
        if (count($all) > 500) {
            $all = array_slice($all, -500);
        }
        $this->store->write('visitors', $all);
    }

    /** Count visitors seen within the window (seconds). */
    public function online(int $window = 90): int
    {
        $cut = time() - $window;
        $n = 0;
        foreach ($this->store->read('visitors') as $v) {
            if (strtotime($v['last_seen'] ?? '') >= $cut) {
                $n++;
            }
        }

        return $n;
    }

    public function isOnline(array $v, int $window = 90): bool
    {
        return strtotime($v['last_seen'] ?? '') >= time() - $window;
    }

    /** Most recently seen first. */
    public function all(): array
    {
        $all = $this->store->read('visitors');
        usort($all, fn ($a, $b) => strtotime($b['last_seen'] ?? '') <=> strtotime($a['last_seen'] ?? ''));

        return $all;
    }

    public function total(): int
    {
        return count($this->store->read('visitors'));
    }
}
