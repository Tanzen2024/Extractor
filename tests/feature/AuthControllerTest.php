<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Login flow at the HTTP layer. AD is unreachable in CI, so every attempt
 * ends in the generic rejection — which is exactly the behaviour to lock in
 * (no 500, no technical detail leaked to the user).
 *
 * @internal
 */
final class AuthControllerTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testLoginPageLoads(): void
    {
        $this->get('login')->assertStatus(200);
    }

    public function testMissingFieldsRedirectWithError(): void
    {
        $result = $this->withSession([])->post('login', [
            'csrf_test_name' => csrf_hash(),
            'username'       => '',
            'password'       => '',
        ]);

        $result->assertRedirect();
        $result->assertSessionHas('error');
    }

    public function testValidFormButAdUnreachableIsRejectedGenerically(): void
    {
        $result = $this->withSession([])->post('login', [
            'csrf_test_name' => csrf_hash(),
            'username'       => 'someone',
            'password'       => 'whatever123',
        ]);

        $result->assertRedirectTo(site_url('login'));
        $result->assertSessionHas('error', 'Identifiant ou mot de passe incorrect.');
        // No application session was opened.
        $this->assertNull(session()->get('isLoggedIn'));
    }

    public function testProtectedPageRedirectsAnonymousToLogin(): void
    {
        $this->get('admin/audit')->assertRedirectTo(site_url('login'));
    }
}
