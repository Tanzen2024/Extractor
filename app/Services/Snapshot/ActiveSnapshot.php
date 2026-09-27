<?php

namespace App\Services\Snapshot;

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
        return (array) ($this->meta['filter_options'] ?? []);
    }

    public function reader(): SnapshotReader
    {
        return new SnapshotReader($this->csvPath, $this->delimiter());
    }
}
