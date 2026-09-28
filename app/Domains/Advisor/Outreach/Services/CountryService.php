<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Country;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Locale;
use Throwable;

/**
 * Target countries (owner decision 2026-09-28: Claude researches them and
 * records them through MCP instead of a fixed seed list).
 *
 * - The code must be a real ISO 3166-1 alpha-2 region (ext-intl).
 * - Exclusion is one-way for Claude: it may mark a country excluded (the safe
 *   direction, with a reason), but only a viloyat advisor in the UI can lift
 *   an exclusion. Excluded countries are refused by CompanyService.
 */
final class CountryService
{
    public const WAVES = ['1', '2', 'investor'];

    public function __construct(
        private readonly OutreachGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:2', 'alpha'],
            'name' => ['nullable', 'string', 'max:120'],
            'wave' => ['nullable', Rule::in(self::WAVES)],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'default_language' => ['nullable', 'string', 'regex:/^[a-z]{2}$/'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'source' => ['nullable', 'string', 'max:500'],
            'excluded' => ['nullable', 'boolean'],
            'excluded_reason' => ['nullable', 'string', 'max:500', 'required_if:excluded,true'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  already shape-validated
     * @return array{country: Country, created: bool}
     */
    public function upsert(Actor $actor, array $data): array
    {
        $this->gate->require($actor, OutreachGate::MANAGE);

        $code = strtoupper((string) $data['code']);
        $isoName = self::isoName($code)
            ?? throw new RuleViolation(RuleViolation::INVALID_INPUT, "«{$code}» ISO 3166 давлат коди эмас.", ['field' => 'code']);

        return DB::connection('advisor')->transaction(function () use ($actor, $data, $code, $isoName): array {
            $country = Country::query()->lockForUpdate()->find($code);
            $created = $country === null;
            $country ??= new Country(['code' => $code, 'excluded' => false]);

            $wantsExcluded = array_key_exists('excluded', $data) ? (bool) $data['excluded'] : null;
            if ($wantsExcluded === false && $country->excluded && ! $this->canLiftExclusion($actor)) {
                throw new RuleViolation(RuleViolation::IRREVERSIBLE, 'Истиснони фақат вилоят маслаҳатчиси интерфейс орқали олиб ташлайди.', ['code' => $code]);
            }

            $country->fill(array_intersect_key($data, array_flip(['wave', 'score', 'default_language', 'timezone', 'notes', 'source'])));
            $country->name = trim((string) ($data['name'] ?? '')) !== '' ? (string) $data['name'] : ($country->name ?? $isoName);
            if ($wantsExcluded !== null) {
                $country->excluded = $wantsExcluded;
                $country->excluded_reason = $wantsExcluded ? (string) $data['excluded_reason'] : null;
            }
            $country->updated_via = $actor->via;

            $changes = $created ? [] : array_keys($country->getDirty());
            $country->save();

            $this->audit->log($actor, $created ? 'country.created' : 'country.updated', 'country', $code, $created
                ? ['name' => $country->name, 'wave' => $country->wave, 'score' => $country->score, 'excluded' => $country->excluded]
                : ['fields' => $changes]);

            return ['country' => $country, 'created' => $created];
        });
    }

    /** English name of an ISO 3166-1 alpha-2 region, or null if it is not one. */
    public static function isoName(string $code): ?string
    {
        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return null;
        }

        try {
            $name = Locale::getDisplayRegion('-'.$code, 'en');
        } catch (Throwable) {
            return null;
        }

        // intl echoes the code back (or returns "Unknown Region") for non-regions.
        return $name === '' || $name === $code || str_contains(strtolower($name), 'unknown') ? null : $name;
    }

    private function canLiftExclusion(Actor $actor): bool
    {
        return $actor->via === Stage::VIA_UI && $this->gate->actorSeesAll($actor);
    }
}
