<?php

use App\Filters\PermissionFilter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The permission route guard:
 *   anonymous               -> redirect to /login
 *   logged in, has perm     -> pass (null)
 *   logged in, missing perm -> 403
 *
 * @internal
 */
final class PermissionFilterTest extends CIUnitTestCase
{
    private PermissionFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new PermissionFilter();
    }

    protected function tearDown(): void
    {
        session()->destroy();
        parent::tearDown();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        session()->destroy();

        $result = $this->filter->before(service('request'), ['USER_VIEW']);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    public function testAuthorisedRequestPasses(): void
    {
        session()->set(['isLoggedIn' => true, 'permissions' => ['USER_VIEW']]);

        $result = $this->filter->before(service('request'), ['USER_VIEW']);

        $this->assertNull($result);
    }

    public function testMissingPermissionGives403(): void
    {
        session()->set(['isLoggedIn' => true, 'permissions' => ['DASHBOARD_VIEW']]);

        $result = $this->filter->before(service('request'), ['USER_VIEW']);

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(403, $result->getStatusCode());
    }

    public function testCommaSeparatedPermissionsAreOr(): void
    {
        session()->set(['isLoggedIn' => true, 'permissions' => ['ROLE_VIEW']]);

        $result = $this->filter->before(service('request'), ['USER_VIEW', 'ROLE_VIEW']);

        $this->assertNull($result);
    }
}
