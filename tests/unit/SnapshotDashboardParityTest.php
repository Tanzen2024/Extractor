<?php

use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\QueryBuilder;
use App\Services\Snapshot\DuckDb;
use App\Services\Snapshot\SnapshotDuckDbEngine;
use App\Services\Snapshot\SnapshotIndex;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotRowSource;
use App\Services\Snapshot\SnapshotScanEngine;
use App\Services\Snapshot\SnapshotUnavailableException;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * The dashboard engine (DuckDB index) gives EXACTLY the answers of the
 * semantic reference (SnapshotScanEngine: a PHP pass with RowMatcher, the
 * export's own matcher) and of the export count, on a snapshot built to hit
 * every rule: padded / blank values, unreadable and garbage dates (0980),
 * day-inclusive date bounds, numeric CONTRACT order, accents in the search,
 * the POSTPAID / PREPAID / Compteurs Communicants segmentations, blank
 * contacts, the "NUI correct" rule — for single and combined filters.
 *
 * A few figures are also checked by hand, so "both engines agree" cannot
 * hide a rule both got wrong.
 *
 * @internal
 */
final class SnapshotDashboardParityTest extends CIUnitTestCase
{
    private const ACTIVE  = ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'INACTIVATION IN PROCESS.', 'SUSPENDED (DELINQUENT ACCOUNT)'];
    private const METERED = ['PREPAID', 'Compteurs Communicants'];

    private SnapshotFixture $fx;
    private SnapshotDuckDbEngine $duck;
    private SnapshotScanEngine $scan;
    private SnapshotRowSource $rowSource;

    protected function setUp(): void
    {
        parent::setUp();
        if ((new DuckDb())->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }

        $this->fx = new SnapshotFixture();
        $r        = $this->fx->install(self::rows());
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);

        $snapshot        = $this->fx->store->active();
        $this->duck      = (new SnapshotIndex($this->fx->config))->engine($snapshot);
        $this->scan      = new SnapshotScanEngine($snapshot);
        $this->rowSource = new SnapshotRowSource(snapshot: $snapshot, store: $this->fx->store);
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
        parent::tearDown();
    }

    /** @return list<array<string, string>> */
    private static function rows(): array
    {
        $regions  = ['DRC', 'DRE', ' DRO ', 'DRC'];
        $statuses = ['ACTIVE', 'ACTIVE (PENDING BILLING)', 'SUSPENDED (DELINQUENT ACCOUNT)', 'INACTIVE.', '', 'INACTIVATION IN PROCESS.'];
        $meters   = ['PREPAID', 'POSTPAID', 'Compteurs Communicants', ' '];
        $segments = [
            'PREPAID'                => ['1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant', '5-Dormant', '6 Old_Dormant', '7 Never Vending', 'Other'],
            'POSTPAID'               => ['1 PERFECT', '2 RELIABLE', '3 Occasional', '4 At-Risk', '5 Delinquant', '6 Always Late', '7 Never Paid', '8 Sometimes Paid', '9 Others'],
            'Compteurs Communicants' => ['1 PERFECT', '4 At-Risk', '9 Others'],
            ' '                      => [''],
        ];
        $dates = ['15/01/2020', '31/12/2019', '01/01/2020', '31/02/2021', '1/4/2024', '01/07/0980', '', '15/06/2024', '29/02/2024'];
        $names = ['Élodie Mbarga', 'JEAN DUPONT', 'Aïcha Bello', 'Paul 9000', 'élève Nana'];

        $rows = [];
        for ($n = 1; $n <= 72; $n++) {
            $meter = $meters[$n % 4];
            $segs  = $segments[$meter];
            $rows[] = SnapshotFixture::row($n, [
                'REGION'        => $regions[$n % 4],
                'DIVISION'      => 'DIV ' . chr(65 + $n % 3),
                'AGENCE'        => 'AG ' . ($n % 5),
                // Lengths 1..6 digits: numeric order differs from text order.
                'CONTRACT'      => (string) ($n * (10 ** ($n % 6))),
                'COD_CLI'       => (string) (900 + ($n * 37) % 100),
                'STATUS'        => $statuses[$n % 6],
                'METER'         => $meter,
                'SEGMENTATION'  => $segs[intdiv($n, 4) % count($segs)],
                'DATE_AB'       => $dates[$n % 9],
                'DATE_RESILIATION' => $n % 7 === 0 ? '10/10/2025' : '',
                'CUST_NAME'     => $names[$n % 5] . ' ' . $n,
                'METER_NO'      => 'mx' . $n,
                'PHONE_NUMBERS' => $n % 3 === 0 ? ' ' : ($n % 3 === 1 ? '' : '690000' . $n),
                'E_MAIL'        => $n % 8 === 0 ? 'c' . $n . '@eneo.cm' : '',
                'NUI_QC'        => ['NUI correct', 'NUI CORRECT', 'NUI àECLASSER', ''][$n % 4],
                'VOLTAGE'       => $n % 10 === 0 ? 'MV' : 'LV',
                'SEGMENT_TRESOR' => $n % 9 === 0 ? 'PUBLIC' : 'PRIVATE',
                'LAST_VC_DATE'  => $n % 2 === 0 ? '01/08/2026' : '',
            ]);
        }

        return $rows;
    }

    /** @return array<string, FilterCriteria> */
    private static function criteriaSets(): array
    {
        return [
            'none'               => FilterCriteria::none(),
            'statuts actifs'     => FilterCriteria::fromArray(['statuses' => self::ACTIVE]),
            'région (valeur paddée dans le fichier)' => FilterCriteria::fromArray(['regions' => ['DRO']]),
            'PREPAID + segments' => FilterCriteria::fromArray(['meters' => ['PREPAID'], 'segmentations' => ['1-Stable', '6 Old_Dormant', 'Other']]),
            'POSTPAID'           => FilterCriteria::fromArray(['meters' => ['POSTPAID'], 'segmentations' => ['1 PERFECT', '4 At-Risk', '9 Others']]),
            'compteurs communicants' => FilterCriteria::fromArray(['meters' => ['Compteurs Communicants']]),
            'bornes de dates incluses' => FilterCriteria::fromArray(['dateFrom' => '2019-12-31', 'dateTo' => '2020-01-15']),
            'date de début seule' => FilterCriteria::fromArray(['dateFrom' => '2024-02-29']),
            'date de fin seule (0980 incluse)' => FilterCriteria::fromArray(['dateTo' => '1990-01-01']),
            'segment trésor + tension + NUI' => FilterCriteria::fromArray(['segmentsTresor' => ['PRIVATE'], 'voltages' => ['LV'], 'niuQualities' => ['NUI correct', 'NUI CORRECT']]),
            'combiné'            => FilterCriteria::fromArray([
                'statuses' => self::ACTIVE, 'regions' => ['DRC', 'DRE'], 'divisions' => ['DIV A', 'DIV B'], 'agences' => ['AG 1', 'AG 2', 'AG 3'],
                'meters' => ['PREPAID', 'POSTPAID'], 'dateFrom' => '2019-01-01', 'dateTo' => '2024-12-31',
            ]),
            'aucun résultat'     => FilterCriteria::fromArray(['regions' => ['DRC'], 'meters' => ['Compteurs Communicants'], 'statuses' => ['INACTIVE.'], 'dateFrom' => '2030-01-01']),
        ];
    }

    /**
     * The production engine (CONTRACT verified unique & numeric at build:
     * plain row counts, CONTRACT-only tie-break) AND the exact one
     * (COUNT(DISTINCT), text tie-break) — both must be the reference.
     *
     * @return array<string, SnapshotDuckDbEngine>
     */
    private function duckEngines(): array
    {
        $snapshot = $this->fx->store->active();

        return [
            'optimisé' => $this->duck,
            'exact'    => new SnapshotDuckDbEngine($snapshot, new DuckDb($this->fx->config), (new SnapshotIndex($this->fx->config))->path($snapshot), false),
        ];
    }

    public function testTheBuildRecordsThatContractsAreUniqueNumbers(): void
    {
        $info = json_decode((string) file_get_contents(SnapshotIndex::infoPath((new SnapshotIndex($this->fx->config))->path($this->fx->store->active()))), true);

        $this->assertSame($this->fx->store->active()->id, $info['version']);
        $this->assertSame(72, $info['rows']);
        $this->assertTrue($info['contract_unique_numeric']);
    }

    public function testEveryAggregateMatchesTheReferenceAndTheExportCount(): void
    {
        foreach ($this->duckEngines() as $mode => $duck) {
            foreach (self::criteriaSets() as $name => $c) {
                $count = $this->scan->count($c);
                $this->assertSame($count, $duck->count($c), "count — {$name} ({$mode})");
                $this->assertSame($count, $this->rowSource->count($c), "export count — {$name}");

                $kpis = $this->scan->kpis($c, self::ACTIVE, self::METERED);
                $this->assertSame($kpis, $duck->kpis($c, self::ACTIVE, self::METERED), "kpis — {$name} ({$mode})");

                $expected = $this->scan->distributions($c);
                $summary  = $duck->summary($c, self::ACTIVE, self::METERED);
                $this->assertSame($kpis, $summary['kpis'], "summary kpis — {$name} ({$mode})");
                foreach ([$duck->distributions($c), $summary['distributions']] as $actual) {
                    foreach (['region', 'status', 'segmentation', 'meterType'] as $dim) {
                        ksort($expected[$dim]);
                        ksort($actual[$dim]);
                        $this->assertSame($expected[$dim], $actual[$dim], "distribution {$dim} — {$name} ({$mode})");
                    }
                }

                $seg  = $this->scan->segmentationCounts($c->withoutSegmentations());
                $dseg = $duck->segmentationCounts($c->withoutSegmentations());
                ksort($seg);
                ksort($dseg);
                $this->assertSame($seg, $dseg, "segmentation counts — {$name} ({$mode})");
            }

            $this->assertSame($this->scan->reference(), $duck->reference(), "reference ({$mode})");
        }
    }

    public function testTablePagesMatchForEverySortDirectionSearchAndOffset(): void
    {
        $sets = self::criteriaSets();
        foreach ($this->duckEngines() as $mode => $duck) {
            foreach (['none' => $sets['none'], 'combiné' => $sets['combiné'], 'statuts actifs' => $sets['statuts actifs']] as $name => $c) {
                foreach (array_merge(QueryBuilder::SORTABLE, [null]) as $sort) {
                    foreach (['asc', 'desc'] as $dir) {
                        foreach ([[0, 7], [5, 9]] as [$offset, $limit]) {
                            $this->assertSame(
                                $this->scan->page($c, '', $sort, $dir, $offset, $limit),
                                $duck->page($c, '', $sort, $dir, $offset, $limit),
                                "page {$name} / " . ($sort ?? 'défaut') . " {$dir} @{$offset} ({$mode})",
                            );
                        }
                    }
                }
            }
        }

        foreach (['élè', 'ÉLODIE', 'dupont', '9000', 'MX1', '37', 'aucune-correspondance', "o'brien"] as $search) {
            $this->assertSame($this->scan->count($sets['none'], $search), $this->duck->count($sets['none'], $search), "search count « {$search} »");
            $this->assertSame(
                $this->scan->page($sets['none'], $search, 'CUST_NAME', 'asc', 0, 50),
                $this->duck->page($sets['none'], $search, 'CUST_NAME', 'asc', 0, 50),
                "search page « {$search} »",
            );
        }
    }

    public function testHandCheckedFigures(): void
    {
        $none = FilterCriteria::none();

        // 72 rows, CONTRACT unique.
        $this->assertSame(72, $this->duck->count($none));
        $this->assertSame(['totalClients' => 72, 'totalNui' => 54], $this->duck->reference()); // NUI_QC blank for n % 4 == 3

        // Active statuses: n % 6 in {0, 1, 2, 5} -> 4 out of every 6 rows.
        $k = $this->duck->kpis($none, self::ACTIVE, self::METERED);
        $this->assertSame(48, $k['actifs']);
        $this->assertSame(24, $k['inactifs'], "'' and INACTIVE. are inactive");
        $this->assertSame(36, $k['avecCompteur'], 'PREPAID + Compteurs Communicants: n % 4 in {0, 2}');
        $this->assertSame(36, $k['nuiCorrect'], '"NUI correct" + "NUI CORRECT" (n % 4 in {0, 1}), case-insensitive; "àECLASSER" and blank are not');
        // contact: phone only for n % 3 == 2 (24 rows), or an e-mail (n % 8 == 0)
        $contacts = count(array_filter(range(1, 72), static fn (int $n): bool => $n % 3 === 2 || $n % 8 === 0));
        $this->assertSame($contacts, $k['contactOk'], 'a blank or single-space phone is no contact');

        // The padded " DRO " in the file is matched by the DRO filter.
        $this->assertSame(18, $this->duck->count(FilterCriteria::fromArray(['regions' => ['DRO']])));

        // Date bounds inclusive at day level; unreadable dates (31/02, 1/4/2024, '') never match a date filter.
        $readable = static fn (int $n): bool => in_array($n % 9, [0, 1, 2, 5, 7, 8], true);
        $this->assertSame(
            count(array_filter(range(1, 72), static fn (int $n): bool => $readable($n) && in_array($n % 9, [0, 1, 2], true))),
            $this->duck->count(FilterCriteria::fromArray(['dateFrom' => '2019-12-31', 'dateTo' => '2020-01-15'])),
        );
        $this->assertSame(8, $this->duck->count(FilterCriteria::fromArray(['dateTo' => '1990-01-01'])), 'the 0980 garbage year is a real (old) date');

        // Default table order = CONTRACT numerically, not as text.
        $contracts = array_column($this->duck->page($none, '', null, 'asc', 0, 72), 'CONTRACT');
        $sorted    = $contracts;
        sort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $contracts);

        // Every table column is present; the ones this snapshot lacks are ''.
        $row = $this->duck->page($none, '', null, 'asc', 0, 1)[0];
        $this->assertSame(QueryBuilder::ALL_COLUMNS, array_keys($row));
        $this->assertSame('2020-01-15', $this->duck->page(FilterCriteria::fromArray(['dateFrom' => '2020-01-15', 'dateTo' => '2020-01-15']), '', null, 'asc', 0, 1)[0]['DATE_AB']);
    }

    public function testPrepaidSegmentationsKeepTheirRawValues(): void
    {
        $seg = $this->duck->segmentationCounts(FilterCriteria::fromArray(['meters' => ['PREPAID']]));

        $this->assertSame(
            ['1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant', '5-Dormant', '6 Old_Dormant', '7 Never Vending', 'Other'],
            array_values(array_intersect(['1-Stable', '2-InStable', '3-At Risk', '4-Suspect Dormant', '5-Dormant', '6 Old_Dormant', '7 Never Vending', 'Other'], array_keys($seg))),
        );
        $this->assertSame(18, array_sum($seg));
    }

    public function testDuplicateOrNonNumericContractsKeepTheExactDistinctCounts(): void
    {
        $fx = new SnapshotFixture();

        try {
            $rows = [];
            foreach ([['C1', 'ACTIVE'], ['C1', 'ACTIVE'], ['C1', 'INACTIVE.'], ['42', 'ACTIVE'], ['7', 'ACTIVE (PENDING BILLING)'], ['X-9', '']] as $i => [$contract, $status]) {
                $rows[] = SnapshotFixture::row($i + 1, ['CONTRACT' => $contract, 'STATUS' => $status, 'DATE_AB' => '15/01/2020']);
            }
            $r = $fx->install($rows);
            $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);

            $snapshot = $fx->store->active();
            $index    = new SnapshotIndex($fx->config);
            $info     = json_decode((string) file_get_contents(SnapshotIndex::infoPath($index->path($snapshot))), true);
            $this->assertFalse($info['contract_unique_numeric'], 'duplicates / letters: no shortcut for this version');

            $duck = $index->engine($snapshot);
            $scan = new SnapshotScanEngine($snapshot);
            $none = FilterCriteria::none();
            $this->assertSame($scan->kpis($none, self::ACTIVE, self::METERED), $duck->kpis($none, self::ACTIVE, self::METERED));
            $this->assertSame(4, $duck->kpis($none, self::ACTIVE, self::METERED)['total'], 'C1, 42, 7, X-9');
            $this->assertSame(6, $duck->count($none), 'the table still has 6 rows');
            foreach (['CONTRACT', 'STATUS'] as $sort) {
                foreach (['asc', 'desc'] as $dir) {
                    $this->assertSame($scan->page($none, '', $sort, $dir, 0, 10), $duck->page($none, '', $sort, $dir, 0, 10), "{$sort} {$dir}");
                }
            }
        } finally {
            $fx->cleanup();
        }
    }

    public function testAValueWithAQuoteIsDataNeverSql(): void
    {
        // Never reachable from the UI (values are validated), checked anyway.
        $c = FilterCriteria::fromArray(['regions' => ["DRC') OR (1=1"]]);
        $this->assertSame(0, $this->duck->count($c));
        $this->assertSame(0, $this->duck->count(FilterCriteria::none(), "x' OR '1'='1"));
    }

    public function testAVersionWithoutIndexIsUnavailableNeverAnotherSource(): void
    {
        $snapshot = $this->fx->store->active();
        unlink((new SnapshotIndex($this->fx->config))->path($snapshot));

        $this->expectException(SnapshotUnavailableException::class);
        (new SnapshotIndex($this->fx->config))->engine($snapshot);
    }
}
