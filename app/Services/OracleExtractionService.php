<?php

namespace App\Services;

use Config\Oracle as OracleConfig;
use RuntimeException;

/**
 * Executes a stored extraction query against Oracle exactly as configured —
 * the SQL text (from tools.query_definition) is never rewritten, wrapped,
 * or concatenated with user input. Bound parameters use oci_bind_by_name.
 *
 * On failure, throws with a short user-facing reference; the real Oracle
 * error is only ever written to the application log.
 */
class OracleExtractionService
{
    /**
     * Safety margin (seconds) added on top of the Oracle call-timeout when
     * raising PHP's own execution time limit for a call. Without a margin,
     * a request could hit both limits at nearly the same instant depending
     * on scheduling jitter; with it, Oracle's own timeout (oci_set_call_timeout,
     * below) always has a real chance to fire and produce a catchable
     * RuntimeException before PHP's timer would.
     */
    public const TIMEOUT_MARGIN_SECONDS = 30;

    private OracleConfig $config;

    public function __construct(?OracleConfig $config = null)
    {
        $this->config = $config ?? new OracleConfig();
    }

    /**
     * @param string               $sql   Verbatim SQL, never modified.
     * @param array<string, mixed> $binds Bind name (without ':') => value.
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public function run(string $sql, array $binds = []): array
    {
        // Guarantee PHP's own time budget covers the Oracle call-timeout plus
        // a margin, so Oracle can time out (and be reported cleanly) before
        // PHP's uncatchable fatal would fire. This only ever *raises* a lower
        // limit — see ensurePhpTimeLimitAtLeast() — so a caller that has
        // already granted itself more time (a bulk export) keeps it.
        self::ensurePhpTimeLimitAtLeast(self::phpTimeLimitFor($this->config->queryTimeoutSeconds));

        $t0           = microtime(true);
        $elapsed      = static fn (): float => round((microtime(true) - $t0) * 1000, 1);
        $memoryBefore = memory_get_usage(true);

        // run() always caps at maxDisplayRows (never more), so it prefetches
        // exactly that many rows in one shot instead of chunking into
        // several round-trips via the general-purpose fetchBatchSize (used
        // by stream(), which has no such upfront cap). Benchmarked against
        // the real production query across several repeated runs: total
        // time is dominated by network/server variance to the remote Oracle
        // host, not by the prefetch size itself — no prefetch value tested
        // (100/500/1000/2000/5000/10000) gave a consistent, large win, but
        // matching prefetch to the row cap had the most stable (lowest
        // variance) total time of those tested, and avoids fetching in
        // arbitrarily-sized chunks when the exact total is already known.
        $maxRows = $this->config->maxDisplayRows;

        log_message('info', 'OCI8_PREFETCH={prefetch} (= Config\\Oracle::$maxDisplayRows for run(); stream() uses $fetchBatchSize={batch} instead)', [
            'prefetch' => $maxRows,
            'batch'    => $this->config->fetchBatchSize,
        ]);

        $connection  = $this->connect();
        $tConnect    = $elapsed();

        try {
            $statement = oci_parse($connection, $sql);

            if (! $statement) {
                $error = oci_error($connection);

                throw new RuntimeException('Oracle parse failed: ' . ($error['message'] ?? 'unknown error'));
            }

            $tParse = $elapsed();

            // OCI8's own default (100 rows/round-trip) turns a 10,000-row
            // fetch into ~100 network round-trips to Oracle — measured to
            // dominate total extraction time far more than SQL execution
            // itself. This only changes how many rows travel per round-trip,
            // never which rows or how many are returned. Must be called
            // before oci_execute() — the Oracle client negotiates the fetch
            // array size as part of the execute call.
            oci_set_prefetch($statement, $maxRows);
            $tPrefetch = $elapsed();

            foreach ($binds as $name => $value) {
                oci_bind_by_name($statement, ':' . $name, $binds[$name]);
            }

            $executed = oci_execute($statement, OCI_DEFAULT);

            if (! $executed) {
                $error = oci_error($statement);

                throw new RuntimeException('Oracle execution failed: ' . ($error['message'] ?? 'unknown error'));
            }

            $tExecute = $elapsed();

            $rows           = [];
            $tFirstFetch    = null;
            $rowCheckpoints = [];

            while (($row = oci_fetch_assoc($statement)) !== false) {
                if ($tFirstFetch === null) {
                    $tFirstFetch = $elapsed();
                }

                $rows[] = $row;
                $n      = count($rows);

                // Batch checkpoints only — never one log line per row.
                if ($n % 1000 === 0) {
                    $rowCheckpoints[$n] = $elapsed();
                }

                if ($n >= $maxRows) {
                    break;
                }
            }

            $tFetchLoopEnd = $elapsed();

            $columns = $rows === [] ? $this->describeColumns($statement) : array_keys($rows[0]);

            $tColumns = $elapsed();

            oci_free_statement($statement);

            log_message('info', 'Extraction timing (ms from call start): connect={connect} parse={parse} set_prefetch={prefetch} execute={execute} first_fetch={firstFetch} fetch_loop_end={fetchEnd} columns={columns} rows={rows} mem_before_mb={memBefore} mem_after_mb={memAfter} mem_peak_mb={memPeak}', [
                'connect'    => $tConnect,
                'parse'      => $tParse,
                'prefetch'   => $tPrefetch,
                'execute'    => $tExecute,
                'firstFetch' => $tFirstFetch,
                'fetchEnd'   => $tFetchLoopEnd,
                'columns'    => $tColumns,
                'rows'       => count($rows),
                'memBefore'  => round($memoryBefore / 1048576, 2),
                'memAfter'   => round(memory_get_usage(true) / 1048576, 2),
                'memPeak'    => round(memory_get_peak_usage(true) / 1048576, 2),
            ]);

            if ($rowCheckpoints !== []) {
                log_message('info', 'Extraction fetch checkpoints (rows => ms from call start): {checkpoints}', [
                    'checkpoints' => json_encode($rowCheckpoints),
                ]);
            }

            return ['columns' => $columns, 'rows' => $rows];
        } finally {
            oci_close($connection);
        }
    }

    /**
     * Executes $sql and invokes $onRow for every fetched row, one at a time,
     * without ever holding the full result set in memory and without the
     * on-screen preview row cap used by run(). Intended for statistical
     * aggregation over large result sets (e.g. the CUSTOMERS_LIST extraction),
     * where only running counters — not the rows themselves — need to be kept.
     *
     * @param callable(array<string, mixed>): void $onRow
     * @param array<string, mixed>                 $binds
     * @param int|null                             $prefetch OCI8 rows-per-round-trip for this
     *     call only; defaults to Config\Oracle::$fetchBatchSize. A bulk export over millions
     *     of rows benefits from a larger value (fewer network round-trips to the remote
     *     Oracle host) without changing which rows are returned.
     *
     * @return int Total number of rows streamed.
     */
    public function stream(string $sql, callable $onRow, array $binds = [], ?int $prefetch = null): int
    {
        // Same rationale as run(): never let PHP's timer kill a streaming
        // extraction before Oracle's own call-timeout has had its chance.
        // Raise-only — a caller with a bigger budget (bulk export) keeps it.
        self::ensurePhpTimeLimitAtLeast(self::phpTimeLimitFor($this->config->queryTimeoutSeconds));

        $connection = $this->connect();
        $count      = 0;

        try {
            $statement = $this->executeStatement($connection, $sql, $binds, $prefetch);

            while (($row = oci_fetch_assoc($statement)) !== false) {
                $onRow($row);
                $count++;
            }

            oci_free_statement($statement);

            return $count;
        } finally {
            oci_close($connection);
        }
    }

    /**
     * @return resource
     */
    private function connect()
    {
        if (! $this->config->isConfigured()) {
            throw new RuntimeException('Oracle connection is not configured.');
        }

        $connection = @oci_connect(
            $this->config->username,
            $this->config->password,
            $this->config->dsn,
            $this->config->charset
        );

        if (! $connection) {
            $error = oci_error();

            throw new RuntimeException('Oracle connection failed: ' . ($error['message'] ?? 'unknown error'));
        }

        if (function_exists('oci_set_call_timeout')) {
            oci_set_call_timeout($connection, $this->config->queryTimeoutSeconds * 1000);
        }

        // NOTE: this low-level connect step deliberately does NOT touch PHP's
        // max_execution_time. The Oracle call-timeout (oci_set_call_timeout,
        // above) protects the Oracle round-trip; PHP's execution budget is a
        // property of the whole business operation and belongs to the caller.
        // An earlier version raised set_time_limit() here unconditionally,
        // which silently *reduced* the budget a bulk export had already set
        // for itself (export set 3600s, this reset it to 330s -> "Maximum
        // execution time of 330 seconds exceeded"). run() and stream() now
        // call ensurePhpTimeLimitAtLeast() instead — raise-only, never clobber.

        return $connection;
    }

    /**
     * PHP's minimum time budget for one extraction call: always at least the
     * configured Oracle timeout plus a safety margin, so Oracle gets to
     * complete or time out on its own terms — and be reported cleanly —
     * rather than PHP cutting the script off mid-call.
     */
    public static function phpTimeLimitFor(int $oracleTimeoutSeconds): int
    {
        return $oracleTimeoutSeconds + self::TIMEOUT_MARGIN_SECONDS;
    }

    /**
     * Raises PHP's max_execution_time to at least $seconds — but never lowers
     * it, and never overrides an already-unlimited budget (max_execution_time
     * of 0, the CLI default). This is the safe primitive every layer uses so
     * that a lower-level step can guarantee its own minimum without ever
     * shrinking a budget a higher-level operation deliberately granted itself.
     */
    public static function ensurePhpTimeLimitAtLeast(int $seconds): void
    {
        if ($seconds <= 0 || ! function_exists('set_time_limit')) {
            return;
        }

        $current = (int) ini_get('max_execution_time');

        // 0 means "no limit" (typical under CLI): already more generous than
        // any finite $seconds, so leave it alone.
        if ($current === 0 || $current >= $seconds) {
            return;
        }

        @set_time_limit($seconds);
    }

    /**
     * Runs a read-only query and returns every row (bounded by $maxRows).
     *
     * For the analytics dashboard: aggregate queries return a handful of
     * rows, a table page returns at most a few hundred — so a plain bounded
     * fetch is the right primitive, distinct from run() (on-screen preview,
     * hard-capped at maxDisplayRows with its own prefetch policy) and
     * stream() (unbounded, row-at-a-time, for exports/aggregation).
     *
     * @param array<string, mixed> $binds Bind name (no ':') => value.
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public function select(string $sql, array $binds = [], int $maxRows = 5000): array
    {
        self::ensurePhpTimeLimitAtLeast(self::phpTimeLimitFor($this->config->queryTimeoutSeconds));

        $connection = $this->connect();

        try {
            $statement = $this->executeStatement($connection, $sql, $binds, min($maxRows, 5000));

            $rows = [];
            while (($row = oci_fetch_assoc($statement)) !== false) {
                $rows[] = $row;

                if (count($rows) >= $maxRows) {
                    break;
                }
            }

            $columns = $rows === [] ? $this->describeColumns($statement) : array_keys($rows[0]);

            oci_free_statement($statement);

            return ['columns' => $columns, 'rows' => $rows];
        } finally {
            oci_close($connection);
        }
    }

    /**
     * Runs several independent read-only queries on ONE Oracle connection
     * (opening a fresh connection per query is a measurable slice of a
     * dashboard cold-load — this is used for the filter-options fan-out).
     *
     * @param array<string, array{sql: string, binds?: array<string, mixed>}> $queries key => {sql, binds}
     *
     * @return array<string, list<array<string, mixed>>> same keys => rows
     */
    public function selectMany(array $queries, int $maxRows = 5000): array
    {
        self::ensurePhpTimeLimitAtLeast(self::phpTimeLimitFor($this->config->queryTimeoutSeconds));

        $connection = $this->connect();
        $out        = [];

        try {
            foreach ($queries as $key => $spec) {
                $statement = $this->executeStatement($connection, $spec['sql'], $spec['binds'] ?? [], min($maxRows, 5000));

                $rows = [];
                while (($row = oci_fetch_assoc($statement)) !== false) {
                    $rows[] = $row;
                    if (count($rows) >= $maxRows) {
                        break;
                    }
                }

                oci_free_statement($statement);
                $out[$key] = $rows;
            }

            return $out;
        } finally {
            oci_close($connection);
        }
    }

    /**
     * @param resource $connection
     *
     * @return resource
     */
    private function executeStatement($connection, string $sql, array $binds, ?int $prefetch = null)
    {
        $statement = oci_parse($connection, $sql);

        if (! $statement) {
            $error = oci_error($connection);

            throw new RuntimeException('Oracle parse failed: ' . ($error['message'] ?? 'unknown error'));
        }

        // OCI8's own default (100 rows/round-trip) turns a 10,000-row fetch
        // into ~100 network round-trips to Oracle — measured to dominate
        // total extraction time far more than SQL execution itself. This
        // only changes how many rows travel per round-trip, never which
        // rows or how many are returned.
        oci_set_prefetch($statement, max(1, $prefetch ?? $this->config->fetchBatchSize));

        foreach ($binds as $name => $value) {
            oci_bind_by_name($statement, ':' . $name, $binds[$name]);
        }

        $executed = oci_execute($statement, OCI_DEFAULT);

        if (! $executed) {
            $error = oci_error($statement);

            throw new RuntimeException('Oracle execution failed: ' . ($error['message'] ?? 'unknown error'));
        }

        return $statement;
    }

    /**
     * @return list<string>
     */
    private function describeColumns($statement): array
    {
        $count   = oci_num_fields($statement);
        $columns = [];

        for ($i = 1; $i <= $count; $i++) {
            $columns[] = oci_field_name($statement, $i);
        }

        return $columns;
    }
}
