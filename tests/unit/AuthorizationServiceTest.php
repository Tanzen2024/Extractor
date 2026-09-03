<?php

use App\Services\AuthorizationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * fromSession() must reflect exactly what login stored — roles/permissions
 * come from MariaDB at login, never from AD groups, and the request-time
 * checks read that session snapshot.
 *
 * @internal
 */
final class AuthorizationServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        session()->set([
            'roles'       => ['DATA_ANALYST'],
            'permissions' => ['DASHBOARD_VIEW', 'AUDIT_VIEW'],
        ]);
    }

    protected function tearDown(): void
    {
        session()->destroy();
        parent::tearDown();
    }

    public function testCanChecksExactPermission(): void
    {
        $authz = AuthorizationService::fromSession();

        $this->assertTrue($authz->can('DASHBOARD_VIEW'));
        $this->assertTrue($authz->can('AUDIT_VIEW'));
        $this->assertFalse($authz->can('USER_CREATE'));
    }

    public function testHasRole(): void
    {
        $authz = AuthorizationService::fromSession();

        $this->assertTrue($authz->hasRole('DATA_ANALYST'));
        $this->assertFalse($authz->hasRole('ADMIN'));
    }

    public function testCanAnyIsOr(): void
    {
        $authz = AuthorizationService::fromSession();

        $this->assertTrue($authz->canAny(['USER_VIEW', 'AUDIT_VIEW']));
        $this->assertFalse($authz->canAny(['USER_VIEW', 'ROLE_VIEW']));
    }

    public function testEmptySessionGrantsNothing(): void
    {
        session()->remove(['roles', 'permissions']);

        $authz = AuthorizationService::fromSession();

        $this->assertSame([], $authz->permissions());
        $this->assertFalse($authz->can('DASHBOARD_VIEW'));
    }
}
