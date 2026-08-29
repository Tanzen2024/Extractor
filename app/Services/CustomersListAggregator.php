<?php

namespace App\Services;

/**
 * Streaming aggregator for the CUSTOMERS_LIST extraction (app/Models/Extractor.sql).
 *
 * Rows are fed one at a time via add() — nothing about the row itself is
 * retained, only running counters — so the whole 500k+ row result set is
 * summarised in a single pass with a small, bounded memory footprint. This
 * is what lets the dashboard compute every KPI/chart from ONE Oracle
 * execution instead of one query per KPI or per filter.
 *
 * Every count is derived strictly from values actually present in the SQL
 * result; nothing here assumes a fixed catalogue of REGION/SEGMENTATION/
 * STATUS/SEGMENT_TRESOR values.
 */
class CustomersListAggregator
{
    private const EMPTY_LABEL = 'Non renseigné';

    /** @var array<string, int> */
    private array $dimTotals = [
        'region'         => [],
        'division'       => [],
        'agence'         => [],
        'meter'          => [],
        'status'         => [],
        'segmentation'   => [],
        'segment_tresor' => [],
        'niu_qc'         => [],
    ];

    /** @var array<string, int> SEGMENT_RFM_2 distribution, prepaid rows only */
    private array $rfmPrepaid = [];

    /** @var array<string, int> SEGMENTATION distribution, postpaid rows only (see class docblock in CustomersListStatsService) */
    private array $postpaidProfile = [];

    /** @var array<string, int> cube key ("\x1f"-joined dimension values) => row count */
    private array $cube = [];

    /** @var array<string, true> distinct COD_CLI seen */
    private array $clients = [];

    private int $rowCount = 0;

    public function add(array $row): void
    {
        $region        = $this->normalize($row['REGION'] ?? null);
        $division      = $this->normalize($row['DIVISION'] ?? null);
        $agence        = $this->normalize($row['AGENCE'] ?? null);
        $meter         = $this->normalize($row['METER'] ?? null);
        $status        = $this->normalize($row['STATUS'] ?? null);
        $segmentation  = $this->normalize($row['SEGMENTATION'] ?? null);
        $segmentTresor = $this->normalize($row['SEGMENT_TRESOR'] ?? null);
        $niuQc         = isset($row['NIU_QC']) && $row['NIU_QC'] !== '' && $row['NIU_QC'] !== null
            ? (string) (int) $row['NIU_QC']
            : self::EMPTY_LABEL;

        $this->rowCount++;

        $codCli = trim((string) ($row['COD_CLI'] ?? ''));
        if ($codCli !== '') {
            $this->clients[$codCli] = true;
        }

        $this->tally('region', $region);
        $this->tally('division', $division);
        $this->tally('agence', $agence);
        $this->tally('meter', $meter);
        $this->tally('status', $status);
        $this->tally('segmentation', $segmentation);
        $this->tally('segment_tresor', $segmentTresor);
        $this->tally('niu_qc', $niuQc);

        $cubeKey = implode("\x1f", [$region, $division, $agence, $meter, $status, $segmentation, $segmentTresor, $niuQc]);
        $this->cube[$cubeKey] = ($this->cube[$cubeKey] ?? 0) + 1;

        if ($meter === 'PREPAID') {
            $rfm                      = $this->normalize($row['SEGMENT_RFM_2'] ?? null, 'Sans profil RFM');
            $this->rfmPrepaid[$rfm]   = ($this->rfmPrepaid[$rfm] ?? 0) + 1;
        }

        if ($meter === 'POSTPAID') {
            $this->postpaidProfile[$segmentation] = ($this->postpaidProfile[$segmentation] ?? 0) + 1;
        }
    }

    /**
     * @return array{
     *     row_count: int,
     *     distinct_client_count: int,
     *     totals: array<string, array<string, int>>,
     *     dimensions: array<string, list<array{value: string, count: int}>>,
     *     cube: list<array<string, mixed>>
     * }
     */
    public function result(): array
    {
        $dimensions = [];
        foreach ($this->dimTotals as $dim => $counts) {
            $dimensions[$dim] = $this->sortedPairs($counts);
        }
        $dimensions['rfm_prepaid']       = $this->sortedPairs($this->rfmPrepaid);
        $dimensions['postpaid_profile']  = $this->sortedPairs($this->postpaidProfile);

        $cube = [];
        foreach ($this->cube as $key => $count) {
            [$region, $division, $agence, $meter, $status, $segmentation, $segmentTresor, $niuQc] = explode("\x1f", $key);

            $cube[] = [
                'region'         => $region,
                'division'       => $division,
                'agence'         => $agence,
                'meter'          => $meter,
                'status'         => $status,
                'segmentation'   => $segmentation,
                'segment_tresor' => $segmentTresor,
                'niu_qc'         => $niuQc,
                'count'          => $count,
            ];
        }

        return [
            'row_count'             => $this->rowCount,
            'distinct_client_count' => $this->clients !== [] ? count($this->clients) : $this->rowCount,
            'totals'                => [
                'meter'  => $this->dimTotals['meter'],
                'niu_qc' => $this->dimTotals['niu_qc'],
            ],
            'dimensions'            => $dimensions,
            'cube'                  => $cube,
        ];
    }

    private function tally(string $dim, string $value): void
    {
        $this->dimTotals[$dim][$value] = ($this->dimTotals[$dim][$value] ?? 0) + 1;
    }

    private function normalize(mixed $value, string $emptyLabel = self::EMPTY_LABEL): string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : $emptyLabel;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{value: string, count: int}>
     */
    private function sortedPairs(array $counts): array
    {
        arsort($counts);

        $pairs = [];
        foreach ($counts as $value => $count) {
            $pairs[] = ['value' => $value, 'count' => $count];
        }

        return $pairs;
    }
}
