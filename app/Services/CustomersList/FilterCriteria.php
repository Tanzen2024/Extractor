<?php

namespace App\Services\CustomersList;

use DateTimeImmutable;

/**
 * Immutable, server-validated set of dashboard filters.
 *
 * The SAME instance drives the KPIs, the charts, the paginated table AND the
 * export — QueryBuilder turns it into one parameterised WHERE clause used
 * everywhere — so "what the dashboard shows" and "what the export contains"
 * can never drift apart.
 *
 * Nothing here is trusted from the request: fromRequest() checks every value
 * against AllowedValues (the live list of real column values) and rejects
 * anything else with InvalidFilterException before any query runs.
 */
final class FilterCriteria
{
    public const DATE_COLUMN = 'DATE_AB'; // subscription date — the only 100%-populated DATE column

    /**
     * @param list<string> $regions
     * @param list<string> $divisions
     * @param list<string> $agences
     * @param list<string> $statuses
     * @param list<string> $segmentations
     * @param list<string> $segmentsTresor
     * @param list<string> $meters
     * @param list<string> $voltages
     * @param list<string> $niuQualities
     */
    private function __construct(
        public readonly ?DateTimeImmutable $dateFrom,
        public readonly ?DateTimeImmutable $dateTo,
        public readonly array $regions,
        public readonly array $divisions,
        public readonly array $agences,
        public readonly array $statuses,
        public readonly array $segmentations,
        public readonly array $segmentsTresor,
        public readonly array $meters,
        public readonly array $voltages,
        public readonly array $niuQualities,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, [], [], [], [], [], [], [], [], []);
    }

    /**
     * @param array<string, mixed> $get   Raw request query params.
     */
    public static function fromRequest(array $get, AllowedValues $allowed): self
    {
        $list = static function (string $key) use ($get): array {
            $raw = $get[$key] ?? [];
            $raw = is_array($raw) ? $raw : [$raw];

            return array_values(array_filter(array_map('strval', $raw), static fn ($v) => $v !== ''));
        };

        $dateFrom = self::parseDate($get['date_from'] ?? null, 'date de début');
        $dateTo   = self::parseDate($get['date_to'] ?? null, 'date de fin');

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new InvalidFilterException('La date de début est postérieure à la date de fin.');
        }

        return new self(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            regions: $allowed->assertSubset('la région', $allowed->regions, $list('region')),
            divisions: $allowed->assertSubset('la division', $allowed->divisions, $list('division')),
            agences: $allowed->assertSubset("l'agence", $allowed->agences, $list('agence')),
            statuses: $allowed->assertSubset('le statut', $allowed->statuses, $list('status')),
            segmentations: $allowed->assertSubset('la segmentation', $allowed->segmentations, $list('segmentation')),
            segmentsTresor: $allowed->assertSubset('le segment trésor', $allowed->segmentsTresor, $list('segment_tresor')),
            meters: $allowed->assertSubset('le type de compteur', $allowed->meters, $list('meter')),
            voltages: $allowed->assertSubset('la tension', $allowed->voltages, $list('voltage')),
            niuQualities: $allowed->assertSubset('la qualité NIU', $allowed->niuQualities, $list('niu_qc')),
        );
    }

    /**
     * Rebuilds a criteria object from its own toArray() output (used to
     * persist a filter set on an export job and replay it in the worker).
     * Trusts the stored data — it was validated when the job was created.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dateFrom: isset($data['dateFrom']) ? new DateTimeImmutable($data['dateFrom']) : null,
            dateTo: isset($data['dateTo']) ? new DateTimeImmutable($data['dateTo']) : null,
            regions: $data['regions'] ?? [],
            divisions: $data['divisions'] ?? [],
            agences: $data['agences'] ?? [],
            statuses: $data['statuses'] ?? [],
            segmentations: $data['segmentations'] ?? [],
            segmentsTresor: $data['segmentsTresor'] ?? [],
            meters: $data['meters'] ?? [],
            voltages: $data['voltages'] ?? [],
            niuQualities: $data['niuQualities'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'dateFrom'       => $this->dateFrom?->format('Y-m-d'),
            'dateTo'         => $this->dateTo?->format('Y-m-d'),
            'regions'        => $this->regions,
            'divisions'      => $this->divisions,
            'agences'        => $this->agences,
            'statuses'       => $this->statuses,
            'segmentations'  => $this->segmentations,
            'segmentsTresor' => $this->segmentsTresor,
            'meters'         => $this->meters,
            'voltages'       => $this->voltages,
            'niuQualities'   => $this->niuQualities,
        ], static fn ($v) => $v !== null && $v !== []);
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * Stable key for caching a response computed from this criteria.
     */
    public function cacheKey(): string
    {
        return md5(json_encode($this->toArray()));
    }

    /**
     * Human-readable "active filters" summary — label => value — for the
     * chips row and the export confirmation modal.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        $out = [];

        if ($this->dateFrom !== null || $this->dateTo !== null) {
            $from = $this->dateFrom?->format('d/m/Y') ?? '…';
            $to   = $this->dateTo?->format('d/m/Y') ?? '…';
            $out['Période (abonnement)'] = "{$from} → {$to}";
        }

        $join = static fn (array $v): string => implode(', ', $v);

        if ($this->regions !== [])        { $out['Région']         = $join($this->regions); }
        if ($this->divisions !== [])      { $out['Division']       = $join($this->divisions); }
        if ($this->agences !== [])        { $out['Agence']         = $join($this->agences); }
        if ($this->statuses !== [])       { $out['Statut']         = $join($this->statuses); }
        if ($this->segmentations !== [])  { $out['Segmentation']   = $join($this->segmentations); }
        if ($this->segmentsTresor !== []) { $out['Segment trésor'] = $join($this->segmentsTresor); }
        if ($this->meters !== [])         { $out['Compteur']       = $join($this->meters); }
        if ($this->voltages !== [])       { $out['Tension']        = $join($this->voltages); }
        if ($this->niuQualities !== [])   { $out['Qualité NIU']    = $join($this->niuQualities); }

        return $out;
    }

    private static function parseDate(mixed $raw, string $label): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $raw = (string) $raw;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            throw new InvalidFilterException("Format de {$label} invalide (attendu AAAA-MM-JJ).");
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw new InvalidFilterException("La {$label} n'est pas une date valide.");
        }

        return $date;
    }
}
