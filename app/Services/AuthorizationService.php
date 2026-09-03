<?php

namespace App\Services;

/**
 * Application authorization — the sole source of truth for what a user may do.
 *
 * Reads roles & permissions from MariaDB (never from AD groups). Two entry
 * points:
 *   - AuthorizationService::forUser($id)   during login, to build the session
 *   - AuthorizationService::fromSession()  during a request, cheap (array only)
 */
class AuthorizationService
{
    /** @var list<string> */
    private array $roles;

    /** @var list<string> */
    private array $permissions;

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function __construct(array $roles, array $permissions)
    {
        $this->roles       = array_values(array_unique($roles));
        $this->permissions = array_values(array_unique($permissions));
    }

    /**
     * Resolves active roles + permissions for a user id straight from the DB.
     */
    public static function forUser(int $userId): self
    {
        $db = db_connect();

        $roleRows = $db->table('app_user_roles ur')
            ->select('r.code')
            ->join('app_roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)
            ->where('r.is_active', 1)
            ->get()->getResultArray();

        $roles = array_column($roleRows, 'code');

        $permRows = $db->table('app_user_roles ur')
            ->select('p.code')
            ->distinct()
            ->join('app_roles r', 'r.id = ur.role_id')
            ->join('app_role_permissions rp', 'rp.role_id = r.id')
            ->join('app_permissions p', 'p.id = rp.permission_id')
            ->where('ur.user_id', $userId)
            ->where('r.is_active', 1)
            ->where('p.is_active', 1)
            ->get()->getResultArray();

        return new self($roles, array_column($permRows, 'code'));
    }

    /**
     * Rebuilds from what login stored in the session.
     */
    public static function fromSession(): self
    {
        $session = session();

        return new self(
            (array) ($session->get('roles') ?? []),
            (array) ($session->get('permissions') ?? []),
        );
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasRole(string $code): bool
    {
        return in_array($code, $this->roles, true);
    }

    /**
     * @param list<string> $permissions
     */
    public function canAny(array $permissions): bool
    {
        return array_intersect($permissions, $this->permissions) !== [];
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->roles;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }
}
