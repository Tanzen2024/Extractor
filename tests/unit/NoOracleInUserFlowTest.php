<?php

use App\Controllers\ExtractionController;
use App\Services\CustomerListExportService;
use App\Services\OracleExtractionService;
use App\Services\Snapshot\SnapshotRowSource;
use App\Services\Snapshot\SnapshotStore;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use Config\Snapshot as SnapshotConfig;

/**
 * Architecture guard: the code of every user flow over customers_list
 * (dashboard, filters, exports, worker) has no way to reach Oracle — it does
 * not even name the Oracle client, the Oracle row source, oci8 or the
 * table. Oracle stays reachable from the snapshot refresh
 * (App\Services\Refresh) and the CLI measuring tools only.
 * SnapshotUserFlowTest proves the same at run time (0 connection attempts).
 *
 * @internal
 */
final class NoOracleInUserFlowTest extends CIUnitTestCase
{
    use ControllerTestTrait;

    /** Files a user request or the export worker executes. */
    private const USER_FLOW = [
        'app/Controllers/DashboardController.php',
        'app/Controllers/ExportJobController.php',
        'app/Services/CustomersList/DashboardService.php',
        'app/Services/CustomersList/FilterCriteria.php',
        'app/Services/CustomersList/AllowedValues.php',
        'app/Services/CustomersList/PrepaidSegmentations.php',
        'app/Services/Export/ExportJobRunner.php',
        'app/Commands/ProcessExportJobs.php',
        'app/Commands/WarmDashboardCache.php',
        'app/Commands/SnapshotIndexCommand.php',
    ];

    private const FORBIDDEN = [
        'OracleExtractionService', 'OracleRowSource', 'oci_', 'TB_CUSTOMERS_LIST', 'CMS_RFC',
        // QueryBuilder's Oracle SQL (its column lists stay usable as constants).
        '->where(', 'kpiStatement', 'pageStatement', 'countStatement', 'exportStatement', 'distributionsStatement',
    ];

    public function testUserFlowCodeNeverNamesOracle(): void
    {
        $files = self::USER_FLOW;
        foreach (glob(APPPATH . 'Services/Snapshot/*.php') ?: [] as $path) {
            $files[] = 'app/Services/Snapshot/' . basename($path);
        }

        foreach ($files as $file) {
            $code = self::codeOnly((string) file_get_contents(ROOTPATH . $file));
            foreach (self::FORBIDDEN as $needle) {
                // ExportJobModel's own query builder calls are not Oracle.
                if ($needle === '->where(' && ! str_contains($code, 'queryBuilder->where(')) {
                    continue;
                }
                $this->assertStringNotContainsString($needle, $code, "{$file} must not reference {$needle}");
            }
        }
    }

    public function testUserExportsAlwaysReadTheSnapshotWhateverTheEnvSays(): void
    {
        $config               = new SnapshotConfig();
        $config->exportSource = 'oracle'; // former rollback switch, still in some .env files
        $config->baseDir      = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bscd_noora_' . bin2hex(random_bytes(4));

        $this->assertTrue($config->usesSnapshot());
        $this->assertTrue($config->legacyOracleSourceRequested(), 'reported by export:doctor');

        try {
            new CustomerListExportService(snapshotConfig: $config);
            $this->fail('no snapshot must mean SnapshotUnavailableException');
        } catch (App\Services\Snapshot\SnapshotUnavailableException) {
            $this->addToAssertionCount(1); // never an OracleRowSource
        } finally {
            SnapshotStore::deleteTree($config->baseDir);
        }
        $this->assertSame(0, OracleExtractionService::$connectionAttempts - self::$attemptsAtStart);
    }

    private static int $attemptsAtStart = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$attemptsAtStart = OracleExtractionService::$connectionAttempts;
    }

    public function testDirectCustomersListExtractionIsRefusedBeforeAnyLookup(): void
    {
        $result = $this->controller(ExtractionController::class)->execute('execute', 'cms', 'customers_list');

        $this->assertSame(403, $result->response()->getStatusCode());
        $this->assertStringContainsString('snapshot', (string) $result->response()->getBody());
        $this->assertSame(0, OracleExtractionService::$connectionAttempts - self::$attemptsAtStart);
    }

    public function testAnyToolReadingTheTableIsRecognised(): void
    {
        $this->assertTrue(ExtractionController::isCustomersList(['code' => 'CUSTOMERS_LIST', 'query_definition' => '']));
        $this->assertTrue(ExtractionController::isCustomersList(['code' => 'MY_COPY', 'query_definition' => 'select * from cms_rfc.tb_customers_list where 1=1']));
        $this->assertFalse(ExtractionController::isCustomersList(['code' => 'OTHER', 'query_definition' => 'select * from cms_rfc.other_table']));
    }

    public function testTheSnapshotRowSourceIsTheDefaultExportSource(): void
    {
        $fx = new Tests\Support\Snapshot\SnapshotFixture();
        try {
            $fx->install([Tests\Support\Snapshot\SnapshotFixture::row(1)]);
            $service = new CustomerListExportService(snapshotConfig: $fx->config);
            $source  = (new ReflectionProperty($service, 'rowSource'))->getValue($service);
            $this->assertInstanceOf(SnapshotRowSource::class, $source);
        } finally {
            $fx->cleanup();
        }
    }

    /** PHP code without comments / docblocks (mentions in prose are fine). */
    private static function codeOnly(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
