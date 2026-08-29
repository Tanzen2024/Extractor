<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Oracle as OracleConfig;

/**
 * Pure unit tests, no Oracle connection required. Verifies the CMS_RFC
 * configuration is loaded from .env as expected, without ever asserting on
 * (or printing) the password value itself.
 *
 * @internal
 */
final class OracleConfigTest extends CIUnitTestCase
{
    public function testUsesCmsRfcCredentialsFromEnv(): void
    {
        $config = new OracleConfig();

        $this->assertSame('CMS_RFC', $config->username);
        $this->assertSame('cmsprod', $config->dsn);
        $this->assertNotSame('', $config->password, 'Oracle password must be set (value intentionally not asserted).');
    }

    public function testIsConfiguredWhenCredentialsPresent(): void
    {
        $config = new OracleConfig();

        $this->assertTrue($config->isConfigured());
    }

    public function testQueryTimeoutIsPositive(): void
    {
        $config = new OracleConfig();

        $this->assertGreaterThan(0, $config->queryTimeoutSeconds);
    }
}
