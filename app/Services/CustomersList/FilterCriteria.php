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
 *
 * It also carries the application's perimeter (ActiveCustomers): $statuses
 * can only hold active statuses, and every query builder filters STATUS on
 * effectiveStatuses() — the selection, or the 4 active statuses when
 * nothing is selected. No criteria object can select an excluded customer.
 */
final class FilterCriteria
{
    public const DATE_COLUMN = 'DATE_AB'; // subscription date — the only 100%-populated DATE column

    /**
     * "Contact renseigné" filter: a contact is filled in when PHONE_NUMBERS or
     * E_MAIL holds real (non-blank) text — the rule of the "Contacts
     * renseignés" KPI. Wire values of the `contact` request key.
     */
    public const CONTACT_COLUMNS = ['PHONE_NUMBERS', 'E_MAIL'];
    public const CONTACT_YES     = 'yes';
    public const CONTACT_NO      = 'no';

    /** @var list<string> STATUT selection, always a subset of ActiveCustomers::STATUSES ([] = all of them). */
    public readonly array $statuses;

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
     * @param bool|null    $hasContact true = contact filled in, false = none, null = no filter
     */
    private function __construct(
        public readonly ?DateTimeImmutable $dateFrom,
        public readonly ?DateTimeImmutable $dateTo,
        public readonly array $regions,
        public readonly array $divisions,
        public readonly array $agences,
        array $statuses,
        public readonly array $segmentations,
        public readonly array $segmentsTresor,
        public readonly array $meters,
        public readonly array $voltages,
        public readonly array $niuQualities,
        public readonly ?bool $hasContact = null,
    ) {
        // Out-of-perimeter statuses are dropped; a selection made ONLY of
        // such statuses (e.g. an export job stored before the perimeter
        // existed) is refused rather than widened to every active status.
        $active = ActiveCustomers::restrict($statuses);
        if ($statuses !== [] && $active === []) {
            throw new InvalidFilterException(lang('Dashboard.validation.invalidValue', ['label' => lang('Glossary.status')]));
        }
        // All four ticked = no narrowing inside the perimeter.
        $this->statuses = count($active) === count(ActiveCustomers::STATUSES) ? [] : $active;
    }

    /**
     * The STATUS values a row must have: the selection, or every active
     * status — never empty, never an excluded status. The ONLY status
     * condition the query builders use.
     *
     * @return list<string>
     */
    public function effectiveStatuses(): array
    {
        return $this->statuses !== [] ? $this->statuses : ActiveCustomers::STATUSES;
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

        $dateFrom = self::parseDate($get['date_from'] ?? null, lang('Dashboard.validation.dateFrom'));
        $dateTo   = self::parseDate($get['date_to'] ?? null, lang('Dashboard.validation.dateTo'));

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new InvalidFilterException(lang('Dashboard.validation.dateOrder'));
        }

        // Every value of a dimension ticked = no restriction on it, exactly
        // like nothing ticked: an IN (all listed values) would silently drop
        // the rows whose value is blank (e.g. 19 013 empty SEGMENT_TRESOR on
        // 2026-09-30), which no option can select. assertSubset() returns a
        // de-duplicated subset of $allowed, so same size = every value.
        $pick = static function (string $label, array $values, string $key) use ($allowed, $list): array {
            $clean = $allowed->assertSubset($label, $values, $list($key));

            return $values !== [] && count($clean) === count(array_unique($values)) ? [] : $clean;
        };

        $meters        = $pick(lang('Glossary.meterType'), $allowed->meters, 'meter');
        $segmentations = $pick(lang('Glossary.segmentation'), $allowed->segmentations, 'segmentation');
        // Both "Oui" and "Non" ticked = no restriction, like any other list.
        $contact = array_values(array_unique($list('contact')));
        foreach ($contact as $v) {
            if ($v !== self::CONTACT_YES && $v !== self::CONTACT_NO) {
                throw new InvalidFilterException(lang('Dashboard.validation.invalidValue', ['label' => lang('Glossary.contactFilled')]));
            }
        }
        $hasContact = count($contact) === 1 ? $contact[0] === self::CONTACT_YES : null;

        // Type compteur = PREPAID only and the 8 PREPAID categories ticked =
        // the whole PREPAID universe, i.e. no segmentation restriction.
        if (PrepaidSegmentations::coversPrepaidScope($segmentations, $meters)) {
            $segmentations = [];
        }

        return new self(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            regions: $pick(lang('Glossary.region'), $allowed->regions, 'region'),
            divisions: $pick(lang('Glossary.division'), $allowed->divisions, 'division'),
            agences: $pick(lang('Glossary.agency'), $allowed->agences, 'agence'),
            statuses: $pick(lang('Glossary.status'), $allowed->statuses, 'status'),
            segmentations: $segmentations,
            segmentsTresor: $pick(lang('Glossary.treasurySegment'), $allowed->segmentsTresor, 'segment_tresor'),
            meters: $meters,
            voltages: $pick(lang('Glossary.voltage'), $allowed->voltages, 'voltage'),
            niuQualities: $pick(lang('Glossary.niuQuality'), $allowed->niuQualities, 'niu_qc'),
            hasContact: $hasContact,
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
            hasContact: isset($data['hasContact']) ? (bool) $data['hasContact'] : null,
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
            'hasContact'     => $this->hasContact,
        ], static fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Same filters without the SEGMENTATION one: the population behind the
     * per-segment counts of the Segmentation dropdown (each count = the
     * other active filters AND that segment, so ticking a segment gives
     * exactly the number shown next to it).
     */
    public function withoutSegmentations(): self
    {
        return new self(
            $this->dateFrom, $this->dateTo, $this->regions, $this->divisions, $this->agences, $this->statuses,
            [], $this->segmentsTresor, $this->meters, $this->voltages, $this->niuQualities, $this->hasContact,
        );
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
        return md5(json_encode([ActiveCustomers::SCOPE, $this->toArray()]));
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
            $out[lang('Glossary.subscriptionPeriod')] = "{$from} → {$to}";
        }

        $join = static fn (array $v): string => implode(', ', $v);

        if ($this->regions !== [])        { $out[lang('Glossary.region')] = $join($this->regions); }
        if ($this->divisions !== [])      { $out[lang('Glossary.division')] = $join($this->divisions); }
        if ($this->agences !== [])        { $out[lang('Glossary.agency')] = $join($this->agences); }
        if ($this->statuses !== [])       { $out[lang('Glossary.status')] = $join($this->statuses); }
        if ($this->segmentations !== [])  { $out[lang('Glossary.segmentation')] = $join($this->segmentations); }
        if ($this->segmentsTresor !== []) { $out[lang('Glossary.treasurySegment')] = $join($this->segmentsTresor); }
        if ($this->meters !== [])         { $out[lang('Glossary.meter')] = $join($this->meters); }
        if ($this->voltages !== [])       { $out[lang('Glossary.voltage')] = $join($this->voltages); }
        if ($this->niuQualities !== [])   { $out[lang('Glossary.niuQuality')] = $join($this->niuQualities); }
        if ($this->hasContact !== null)   { $out[lang('Glossary.contactFilled')] = lang($this->hasContact ? 'App.yes' : 'App.no'); }

        return $out;
    }

    private static function parseDate(mixed $raw, string $label): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $raw = (string) $raw;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            throw new InvalidFilterException(lang('Dashboard.validation.dateFormat', ['label' => $label]));
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw new InvalidFilterException(lang('Dashboard.validation.dateInvalid', ['label' => $label]));
        }

        return $date;
    }
}
