<?php

use App\Models\CustomersSyncRunModel;
use App\Services\Snapshot\DuckDb;
use App\Services\Snapshot\SnapshotDiff;
use App\Services\Snapshot\SnapshotInstaller;
use App\Services\Snapshot\SnapshotStore;
use App\Services\Snapshot\SyncFreshness;
use App\Services\Snapshot\SyncStatusReader;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Snapshot\SnapshotFixture;

/**
 * The freshness indicator and the comparison between two versions. The rule
 * checked throughout: the indicator follows the date the active version was
 * really validated, and a failed or interrupted run never reads as a success.
 *
 * @internal
 */
final class CustomersSyncFreshnessTest extends CIUnitTestCase
{
    private const NOW = '2026-11-06 10:00:00';

    private ?SnapshotFixture $fx = null;

    protected function tearDown(): void
    {
        $this->fx?->cleanup();
        parent::tearDown();
    }

    /** @param array<string, mixed>|null $lastRun */
    private static function state(?string $lastSuccess, ?array $lastRun = null, string $now = self::NOW): array
    {
        return SyncFreshness::evaluate(
            $lastSuccess !== null ? new DateTimeImmutable($lastSuccess) : null,
            $lastRun,
            new DateTimeImmutable($now),
            26,
            6,
        );
    }

    public function testSuccessWithinTheDelayIsFresh(): void
    {
        $this->assertSame(SyncFreshness::FRESH, self::state('2026-11-06 05:30:00', ['status' => 'success', 'finished_at' => '2026-11-06 05:30:00'])['state']);
        $this->assertSame(SyncFreshness::FRESH, self::state('2026-11-05 08:00:00')['state'], '26 h exactly is still on time');
    }

    public function testNoSuccessWithinTheDelayIsLate(): void
    {
        $this->assertSame(SyncFreshness::LATE, self::state('2026-11-05 07:59:59')['state']);
        $this->assertSame(SyncFreshness::LATE, self::state(null)['state'], 'nothing ever synchronised');
    }

    public function testLatestAttemptFailedIsFailedEvenWithRecentData(): void
    {
        $this->assertSame(SyncFreshness::FAILED, self::state('2026-11-06 05:30:00', ['status' => 'failed', 'finished_at' => '2026-11-06 06:30:00'])['state']);
    }

    public function testFailureOlderThanTheActiveVersionNoLongerCounts(): void
    {
        // e.g. a version delivered by the SFTP push after the failed refresh.
        $this->assertSame(SyncFreshness::FRESH, self::state('2026-11-06 07:00:00', ['status' => 'failed', 'finished_at' => '2026-11-06 06:30:00'])['state']);
    }

    public function testUnchangedSourceNeverMakesOldDataFresh(): void
    {
        $this->assertSame(SyncFreshness::LATE, self::state('2026-10-03 09:28:23', ['status' => 'skipped', 'finished_at' => '2026-11-06 05:00:12'])['state']);
    }

    public function testRunInProgressKeepsTheStateOfTheDataAndSaysSo(): void
    {
        $r = self::state('2026-10-03 09:28:23', ['status' => 'running', 'started_at' => '2026-11-06 09:50:00']);

        $this->assertSame(SyncFreshness::LATE, $r['state']);
        $this->assertTrue($r['running']);
    }

    public function testRunThatNeverEndedIsAFailureNotARunInProgress(): void
    {
        $r = self::state('2026-11-05 22:00:00', ['status' => 'running', 'started_at' => '2026-11-06 03:00:00']);

        $this->assertSame(SyncFreshness::FAILED, $r['state']);
        $this->assertFalse($r['running']);
        $this->assertTrue($r['interrupted']);
    }

    public function testNextRunIsTodayOrTomorrowAndUnknownWithoutSchedule(): void
    {
        $this->assertSame('2026-11-07 05:00', SyncFreshness::nextRun('05:00', new DateTimeImmutable(self::NOW))->format('Y-m-d H:i'));
        $this->assertSame('2026-11-06 05:00', SyncFreshness::nextRun('05:00', new DateTimeImmutable('2026-11-06 04:59:00'))->format('Y-m-d H:i'));
        $this->assertNull(SyncFreshness::nextRun(null, new DateTimeImmutable(self::NOW)));
        $this->assertNull(SyncFreshness::nextRun('25:00', new DateTimeImmutable(self::NOW)));
    }

    public function testReaderUsesTheValidationDateOfTheActiveVersionNotACustomerDate(): void
    {
        $this->fx = new SnapshotFixture();
        $this->fx->config->refreshScheduleFile = $this->fx->baseDir . '/schedule.json';
        $this->fx->install([SnapshotFixture::row(1, ['UPDATED_AT' => '1999-01-01 00:00:00'])]);

        $active = $this->fx->store->active();
        $reader = new SyncStatusReader($this->fx->config, static fn () => throw new RuntimeException('MariaDB down'));
        $status = $reader->read(new DateTimeImmutable($active->meta['validated_at']));

        $this->assertSame(SyncFreshness::FRESH, $status['state'], 'history unavailable: the snapshot alone decides');
        $this->assertSame($active->meta['validated_at'], $status['lastSuccessAt']);
        $this->assertSame($active->id, $status['version']);
        $this->assertNull($status['lastStatus']);
        $this->assertNull($status['nextRunAt'], 'no installer ran: no run may be announced');

        file_put_contents($this->fx->config->refreshScheduleFile, '{"daily_at":"05:00"}');
        $this->assertSame('05:00', $reader->read()['scheduledAt']);
        $this->assertNotNull($reader->read()['nextRunAt']);
    }

    public function testLastSuccessIsWhenTheRunEndedNotWhenTheFileWasValidated(): void
    {
        $this->fx = new SnapshotFixture();
        $this->fx->config->refreshScheduleFile = $this->fx->baseDir . '/schedule.json';
        $this->fx->install([SnapshotFixture::row(1)]);

        $model = new class () extends CustomersSyncRunModel {
            public function latest(): ?array
            {
                return ['status' => 'success', 'started_at' => '2030-01-01 10:05:38', 'finished_at' => '2030-01-01 12:09:39'];
            }

            public function successOf(string $version): ?array
            {
                return ['finished_at' => '2030-01-01 12:09:39'];
            }
        };

        $status = (new SyncStatusReader($this->fx->config, static fn () => $model))->read(new DateTimeImmutable('2030-01-01 13:00:00'));

        $this->assertStringStartsWith('2030-01-01T12:09:39', $status['lastSuccessAt']);
        $this->assertSame(SyncFreshness::FRESH, $status['state']);
    }

    public function testReaderWithoutSnapshotIsLateAndDoesNotFail(): void
    {
        $this->fx = new SnapshotFixture();
        $this->fx->config->refreshScheduleFile = $this->fx->baseDir . '/schedule.json';

        $status = (new SyncStatusReader($this->fx->config, static fn () => throw new RuntimeException('MariaDB down')))->read();

        $this->assertSame(SyncFreshness::LATE, $status['state']);
        $this->assertNull($status['lastSuccessAt']);
        $this->assertSame([], (new SyncStatusReader($this->fx->config, static fn () => throw new RuntimeException('down')))->history(5));
    }

    public function testRetainedVersionIsNeverPruned(): void
    {
        $this->fx = new SnapshotFixture();
        $this->fx->install([SnapshotFixture::row(1)]);
        $oldest = $this->fx->store->active()->id;
        file_put_contents($this->fx->store->versionDir($oldest) . DIRECTORY_SEPARATOR . SnapshotStore::RETAIN_FILE, 'keep');

        for ($i = 2; $i <= 4; $i++) {
            $this->fx->install([SnapshotFixture::row($i)]);
        }

        $versions = $this->fx->store->versions();
        $this->assertContains($oldest, $versions, 'retained on purpose');
        $this->assertCount(3, $versions, 'active + one previous + the retained one: rotation still works');
    }

    public function testComparisonFindsAddedModifiedRemovedAndInactivatedCustomers(): void
    {
        if ((new DuckDb())->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }

        $this->fx = new SnapshotFixture();
        $stamp    = ['UPDATED_AT' => '2026-11-05 04:32:00'];
        $this->fx->install([
            SnapshotFixture::row(1, $stamp),
            SnapshotFixture::row(2, $stamp),
            SnapshotFixture::row(3, $stamp),
            SnapshotFixture::row(4, $stamp),
        ]);
        $previous = $this->fx->store->active();

        // UPDATED_AT differs on every row and must not make them "modified".
        $stamp = ['UPDATED_AT' => '2026-11-06 04:32:00'];
        $r     = $this->fx->install([
            SnapshotFixture::row(1, $stamp),
            SnapshotFixture::row(2, $stamp + ['CUST_NAME' => 'Nouveau nom']),
            SnapshotFixture::row(3, $stamp + ['STATUS' => 'INACTIVE.']),
            SnapshotFixture::row(5, $stamp),
        ]);
        $this->assertSame(SnapshotInstaller::RESULT_ACTIVATED, $r['result'], (string) $r['message']);

        $diff = (new SnapshotDiff($this->fx->config))->compare($this->fx->store->active(), $previous);

        $this->assertSame(1, $diff['rows_added'], 'contract 5');
        $this->assertSame(2, $diff['rows_modified'], 'contracts 2 and 3');
        $this->assertSame(1, $diff['rows_ignored'], 'contract 1');
        $this->assertSame(1, $diff['rows_removed'], 'contract 4');
        $this->assertSame(1, $diff['rows_inactivated'], 'contract 3');
        $this->assertSame(3, $diff['rows_active']);
        $this->assertSame(0, $diff['rows_duplicate']);
    }

    public function testFirstVersionHasNoComparisonButStillItsActiveCount(): void
    {
        if ((new DuckDb())->version() === null) {
            $this->markTestSkipped('DuckDB CLI absent (Config\Snapshot::$duckdbBinary).');
        }

        $this->fx = new SnapshotFixture();
        $this->fx->install([SnapshotFixture::row(1), SnapshotFixture::row(2, ['STATUS' => 'INACTIVE.'])]);

        $diff = (new SnapshotDiff($this->fx->config))->compare($this->fx->store->active(), null);

        $this->assertSame(1, $diff['rows_active']);
        $this->assertNull($diff['rows_added']);
    }
}
