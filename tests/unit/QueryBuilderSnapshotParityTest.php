<?php

use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\QueryBuilder;
use App\Services\Snapshot\DuckDb;
use App\Services\Snapshot\SnapshotIndex;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotRowSource;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * Business parity of the filters across the move from Oracle to the
 * snapshot: the WHERE clause QueryBuilder::where() built for Oracle — the
 * very SQL and binds the former dashboard and exports ran — is executed as
 * is on the snapshot's DuckDB index (Oracle's TO_DATE emulated by a macro,
 * binds inlined as quoted literals), and must select exactly the rows
 * RowMatcher (the exports) and the dashboard engine select.
 *
 * Known, documented difference: RowMatcher compares trimmed values, Oracle
 * compares raw ones — the live table holds no padded filter value (checked
 * 2026-09-27), so this dataset has none either.
 *
 * @internal
 */
final class QueryBuilderSnapshotParityTest extends CIUnitTestCase
{
    private SnapshotFixture $fx;

    protected function setUp(): void
    {
        parent::setUp();
        if ((new DuckDb())->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }
        $this->fx = new SnapshotFixture();

        $statuses = ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'INACTIVATION IN PROCESS.', 'SUSPENDED (DELINQUENT ACCOUNT)', 'INACTIVE.'];
        $meters   = ['PREPAID', 'POSTPAID', 'Compteurs Communicants'];
        $segments = [
            'PREPAID'                => ['1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant', '5-Dormant', '6 Old_Dormant', '7 Never Vending', 'Other'],
            'POSTPAID'               => ['1 PERFECT', '2 RELIABLE', '3 Occasional', '4 At-Risk', '5 Delinquant', '6 Always Late', '7 Never Paid', '8 Sometimes Paid', '9 Others'],
            'Compteurs Communicants' => ['1 PERFECT', '2 RELIABLE', '4 At-Risk', '9 Others'],
        ];
        $dates = ['15/01/2020', '31/12/2019', '01/01/2020', '29/02/2024', '01/07/0980', '31/12/2024', '01/01/2025'];

        $rows = [];
        for ($n = 1; $n <= 90; $n++) {
            $meter = $meters[$n % 3];
            $rows[] = SnapshotFixture::row($n, [
                'REGION'         => ['DCUD', 'DCUY', 'DRONO'][$n % 3 === 0 ? 2 : $n % 2],
                'DIVISION'       => 'DVC ' . ($n % 4),
                'AGENCE'         => 'CSC ' . ($n % 6),
                'STATUS'         => $statuses[$n % 5],
                'METER'          => $meter,
                'SEGMENTATION'   => $segments[$meter][intdiv($n, 3) % count($segments[$meter])],
                'SEGMENT_TRESOR' => $n % 4 === 0 ? 'PUBLIC' : 'PRIVATE',
                'VOLTAGE'        => $n % 5 === 0 ? 'MV' : 'LV',
                'NUI_QC'         => $n % 3 === 0 ? 'NUI àECLASSER' : 'NUI CORRECT',
                'DATE_AB'        => $dates[$n % 7],
            ]);
        }
        $r = $this->fx->install($rows);
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
        parent::tearDown();
    }

    /** Rows Oracle's WHERE (QueryBuilder::where) selects, run on the snapshot index. */
    private function oracleWhereCount(FilterCriteria $c): int
    {
        $where = (new QueryBuilder())->where($c);
        $sql   = preg_replace_callback('/:(f\d+)\b/', static fn (array $m): string => DuckDb::literal((string) $where['binds'][$m[1]]), $where['sql']);
        $index = (new SnapshotIndex($this->fx->config))->path($this->fx->store->active());

        $rows = (new DuckDb($this->fx->config))->query($index, "CREATE TEMP MACRO TO_DATE(s, f) AS CAST(s AS DATE);\n"
            . 'SELECT COUNT(*) AS n FROM cl' . ($sql === '' ? '' : ' WHERE ' . $sql));

        return (int) $rows[0]['n'];
    }

    /** @return array<string, FilterCriteria> */
    public static function criteria(): array
    {
        $active = ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'INACTIVATION IN PROCESS.', 'SUSPENDED (DELINQUENT ACCOUNT)'];

        return [
            'aucun filtre'          => FilterCriteria::none(),
            'date de début'         => FilterCriteria::fromArray(['dateFrom' => '2020-01-01']),
            'date de fin (incluse)' => FilterCriteria::fromArray(['dateTo' => '2024-12-31']),
            'plage de dates'        => FilterCriteria::fromArray(['dateFrom' => '2019-12-31', 'dateTo' => '2020-01-15']),
            'région'                => FilterCriteria::fromArray(['regions' => ['DCUD']]),
            'division'              => FilterCriteria::fromArray(['divisions' => ['DVC 1', 'DVC 3']]),
            'agence'                => FilterCriteria::fromArray(['agences' => ['CSC 2']]),
            'statut'                => FilterCriteria::fromArray(['statuses' => $active]),
            'POSTPAID'              => FilterCriteria::fromArray(['meters' => ['POSTPAID'], 'segmentations' => ['1 PERFECT', '4 At-Risk', '8 Sometimes Paid']]),
            'PREPAID'               => FilterCriteria::fromArray(['meters' => ['PREPAID'], 'segmentations' => ['1-Stable', '3-At Risk', '6 Old_Dormant', 'Other']]),
            'COMPTEURS COMMUNICANTS' => FilterCriteria::fromArray(['meters' => ['Compteurs Communicants'], 'segmentations' => ['1 PERFECT', '9 Others']]),
            'segment Trésor'        => FilterCriteria::fromArray(['segmentsTresor' => ['PUBLIC']]),
            'compteur'              => FilterCriteria::fromArray(['meters' => ['PREPAID', 'Compteurs Communicants']]),
            'tension'               => FilterCriteria::fromArray(['voltages' => ['MV']]),
            'NUI QC'                => FilterCriteria::fromArray(['niuQualities' => ['NUI CORRECT']]),
            'combiné'               => FilterCriteria::fromArray([
                'statuses' => $active, 'regions' => ['DCUD', 'DCUY'], 'divisions' => ['DVC 0', 'DVC 1', 'DVC 2'], 'meters' => ['PREPAID', 'POSTPAID'],
                'segmentsTresor' => ['PRIVATE'], 'voltages' => ['LV'], 'niuQualities' => ['NUI CORRECT'], 'dateFrom' => '2019-01-01', 'dateTo' => '2024-12-31',
            ]),
        ];
    }

    public function testEveryFilterSelectsTheSameRowsAsTheFormerOracleWhere(): void
    {
        $snapshot = $this->fx->store->active();
        $engine   = (new SnapshotIndex($this->fx->config))->engine($snapshot);
        $exports  = new SnapshotRowSource(snapshot: $snapshot, store: $this->fx->store);

        foreach (self::criteria() as $name => $c) {
            $oracle = $this->oracleWhereCount($c);
            $this->assertSame($oracle, $exports->count($c), "RowMatcher (exports) ≠ QueryBuilder::where() — {$name}");
            $this->assertSame($oracle, $engine->count($c), "dashboard engine ≠ QueryBuilder::where() — {$name}");
        }
    }

    public function testTheSetsAreNotTrivial(): void
    {
        $counts = array_map(fn (FilterCriteria $c): int => $this->oracleWhereCount($c), self::criteria());

        $this->assertSame(90, $counts['aucun filtre']);
        foreach ($counts as $name => $n) {
            if ($name !== 'aucun filtre') {
                $this->assertGreaterThan(0, $n, $name);
                $this->assertLessThan(90, $n, $name);
            }
        }
    }
}
