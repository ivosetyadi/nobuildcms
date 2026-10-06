<?php

declare(strict_types=1);

namespace NoBuildCMS;

/** Minimal session auth over users.json. */
final class Auth
{
    public function __construct(private Store $store)
    {
    }

    public function attempt(string $email, string $password): bool
    {
        foreach ($this->store->all('users') as $u) {
            if (strcasecmp($u['email'] ?? '', $email) === 0
                && ($u['active'] ?? false)
                && password_verify($password, $u['password'] ?? '')) {
                $_SESSION['uid'] = $u['id'];
                $this->store->save('users', ['id' => $u['id'], 'last_login' => date('c')]);

                return true;
            }
        }

        return false;
    }

    public function user(): ?array
    {
        $uid = $_SESSION['uid'] ?? null;

        return $uid ? $this->store->findById('users', $uid) : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function can(string $action): bool
    {
        $role = $this->user()['role'] ?? 'guest';

        // owner: all; editor: content/media/chat; viewer: read-only.
        return match ($role) {
            'owner' => true,
            'editor' => $action !== 'manage_users' && $action !== 'manage_settings',
            default => $action === 'view',
        };
    }

    public function logout(): void
    {
        unset($_SESSION['uid']);
    }
}
