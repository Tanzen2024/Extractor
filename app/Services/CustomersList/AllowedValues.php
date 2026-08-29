<?php

namespace App\Services\CustomersList;

/**
 * The set of filter values the server is willing to accept, built from the
 * live filter-options payload (itself distinct values pulled straight from
 * CMS_RFC.TB_CUSTOMERS_LIST). FilterCriteria::fromRequest() checks every
 * incoming value against this before a single row is queried.
 *
 * Geographic values are validated flat (is this a real region / division /
 * agence at all) — the region->division->agence *consistency* of a
 * selection is a frontend nicety, not a security boundary: an odd but
 * real combination just returns no rows.
 */
final class AllowedValues
{
    /**
     * @param list<string>            $regions
     * @param list<string>            $divisions
     * @param list<string>            $agences
     * @param list<string>            $statuses
     * @param list<string>            $segmentations
     * @param list<string>            $segmentsTresor
     * @param list<string>            $meters
     * @param list<string>            $voltages
     * @param array{min:?string,max:?string} $dateBounds YYYY-MM-DD, inclusive
     */
    public function __construct(
        public readonly array $regions,
        public readonly array $divisions,
        public readonly array $agences,
        public readonly array $statuses,
        public readonly array $segmentations,
        public readonly array $segmentsTresor,
        public readonly array $meters,
        public readonly array $voltages,
        public readonly array $dateBounds = ['min' => null, 'max' => null],
    ) {
    }

    /**
     * @param array<string, mixed> $options Payload from DashboardService::filterOptions().
     */
    public static function fromFilterOptions(array $options): self
    {
        $values = static fn (string $key): array => array_values(array_map(
            static fn (array $row): string => (string) $row['value'],
            $options[$key] ?? []
        ));

        return new self(
            regions: $values('regions'),
            divisions: $values('divisions'),
            agences: $values('agences'),
            statuses: $values('statuses'),
            segmentations: $values('segmentations'),
            segmentsTresor: $values('segmentsTresor'),
            meters: $values('meters'),
            voltages: $values('voltages'),
            dateBounds: $options['dateBounds'] ?? ['min' => null, 'max' => null],
        );
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $submitted
     *
     * @return list<string> The submitted values, de-duplicated, in submitted order.
     *
     * @throws InvalidFilterException if any submitted value is not in $allowed.
     */
    public function assertSubset(string $label, array $allowed, array $submitted): array
    {
        $clean = [];

        foreach ($submitted as $value) {
            $value = (string) $value;

            if ($value === '') {
                continue;
            }

            if (! in_array($value, $allowed, true)) {
                throw new InvalidFilterException("Valeur de filtre invalide pour {$label}.");
            }

            if (! in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }

        return $clean;
    }
}
