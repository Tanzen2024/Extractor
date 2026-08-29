<?php

use App\Filters\CsrfTokenHeaderFilter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The filter must stamp the *current* CSRF hash on every response as
 * X-CSRF-TOKEN, so bscdFetch() can keep the browser's token in sync across
 * several POSTs (Config\Security::$regenerate rotates it after each one).
 * Without this, the second export POST 403'd and the UI mis-rendered it as
 * "Export volumineux (0 lignes)".
 *
 * @internal
 */
final class CsrfTokenHeaderFilterTest extends CIUnitTestCase
{
    public function testAfterAddsTheCurrentCsrfHashHeader(): void
    {
        $request  = service('request');
        $response = service('response');

        (new CsrfTokenHeaderFilter())->after($request, $response);

        $this->assertTrue($response->hasHeader('X-CSRF-TOKEN'));
        $this->assertSame(csrf_hash(), $response->getHeaderLine('X-CSRF-TOKEN'));
        $this->assertNotSame('', $response->getHeaderLine('X-CSRF-TOKEN'));
    }

    public function testItIsRegisteredAsAGlobalAfterFilter(): void
    {
        $filters = config(\Config\Filters::class);

        $this->assertArrayHasKey('csrftoken', $filters->aliases);
        $this->assertSame(CsrfTokenHeaderFilter::class, $filters->aliases['csrftoken']);
        $this->assertContains('csrftoken', $filters->globals['after']);
    }
}
