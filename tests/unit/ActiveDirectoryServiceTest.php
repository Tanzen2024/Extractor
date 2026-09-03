<?php

use App\Services\ActiveDirectoryService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Username normalisation before the LDAP bind / directory search.
 *
 * The login screen recommends a bare sAMAccountName, but a user may paste a
 * full UPN or a legacy NetBIOS form. The bind RDN must never end up with a
 * double "@domain" suffix, and the app_users lookup must always use the bare
 * sAMAccountName.
 *
 * @internal
 */
final class ActiveDirectoryServiceTest extends CIUnitTestCase
{
    private function bindRdn(string $input, string $domain = 'camlight.cm'): string
    {
        $m = (new ReflectionMethod(ActiveDirectoryService::class, 'toBindRdn'));
        $m->setAccessible(true);

        return $m->invoke(null, $input, $domain);
    }

    private function sam(string $input): string
    {
        $m = (new ReflectionMethod(ActiveDirectoryService::class, 'toSamAccountName'));
        $m->setAccessible(true);

        return $m->invoke(null, $input);
    }

    public function testBareAccountGetsTheDomainAppended(): void
    {
        $this->assertSame('hugues.nwameh@camlight.cm', $this->bindRdn('hugues.nwameh'));
    }

    public function testFullUpnIsLeftUntouched(): void
    {
        $this->assertSame('hugues.nwameh@camlight.cm', $this->bindRdn('hugues.nwameh@camlight.cm'));
        // and never doubled
        $this->assertStringNotContainsString('@camlight.cm@camlight.cm', $this->bindRdn('hugues.nwameh@camlight.cm'));
    }

    public function testNetbiosPrefixIsStrippedThenQualified(): void
    {
        $this->assertSame('hugues.nwameh@camlight.cm', $this->bindRdn('CAMLIGHT\\hugues.nwameh'));
    }

    public function testSamAccountNameIsAlwaysBare(): void
    {
        $this->assertSame('hugues.nwameh', $this->sam('hugues.nwameh'));
        $this->assertSame('hugues.nwameh', $this->sam('hugues.nwameh@camlight.cm'));
        $this->assertSame('hugues.nwameh', $this->sam('CAMLIGHT\\hugues.nwameh'));
        $this->assertSame('hugues.nwameh', $this->sam('  hugues.nwameh  '));
    }
}
