<?php

namespace App\Services\Snapshot;

use App\Services\CustomersList\FailureSubcategories;
use App\Services\CustomersList\PostpaidSegmentations;
use App\Services\CustomersList\PrepaidSegmentations;

/**
 * One validated, immutable snapshot version, resolved once by an export and
 * kept for its whole duration: a newer version activated meanwhile never
 * affects an export already holding this object.
 */
final class ActiveSnapshot
{
    /**
     * @param array<string, mixed> $meta Contents of the version's customers_list.meta.json.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $csvPath,
        public readonly array $meta,
    ) {
    }

    public function delimiter(): string
    {
        return (string) $this->meta['delimiter'];
    }

    public function rows(): int
    {
        return (int) $this->meta['rows'];
    }

    public function dateFormat(): ?string
    {
        $format = $this->meta['date_ab_format'] ?? null;

        return is_string($format) && $format !== '' ? $format : null;
    }

    /** @return array<string, mixed> Same shape as DashboardService::filterOptions(). */
    public function filterOptions(): array
    {
        return PostpaidSegmentations::foldOptions((array) ($this->meta['filter_options'] ?? []));
    }

    /**
     * @param bool $withFailure records also carry FailureSubcategories::COLUMN
     *        (off by default: the export keeps its one-to-one record layout).
     */
    public function reader(bool $withFailure = false): SnapshotReader
    {
        $reader = new SnapshotReader($this->csvPath, $this->delimiter());
        if ($withFailure) {
            $reader->derive(FailureSubcategories::COLUMN, 'SEGMENTATION', FailureSubcategories::LEGACY);
        }

        return $reader->translate('SEGMENTATION', PostpaidSegmentations::ALL_LEGACY)
            ->translate('SEGMENT_RFM_2', PrepaidSegmentations::LEGACY);
    }
}
