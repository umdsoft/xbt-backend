<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Http;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Touch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * JSON shapes from docs/outreach/API-contract.md, shared by the REST API and
 * the MCP tools so both channels describe a lead the same way.
 *
 * User names live in the auth schema, so they are resolved in one batch per
 * response (no N+1, no cross-schema join).
 */
final class OutreachPresenter
{
    /** @var array<string, ?string> */
    private array $names = [];

    /** @param  iterable<?string>  $ids */
    public function preloadNames(iterable $ids): self
    {
        $missing = collect($ids)->filter()->unique()->reject(fn (string $id): bool => array_key_exists($id, $this->names));

        if ($missing->isNotEmpty()) {
            $found = User::query()->whereIn('id', $missing->all())->pluck('name', 'id');
            foreach ($missing as $id) {
                $this->names[$id] = $found[$id] ?? null;
            }
        }

        return $this;
    }

    public function name(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }
        $this->preloadNames([$userId]);

        return $this->names[$userId];
    }

    /** @return array<string, mixed> */
    public function companyRow(Company $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'domain' => $c->domain,
            'country_code' => $c->country_code,
            'country_name' => $c->country?->name,
            'wave' => $c->country?->wave,
            'stage' => $c->stage,
            'tier' => $c->tier,
            'icp_score' => $c->icp_score,
            'owner_user_id' => $c->owner_user_id,
            'owner_name' => $this->name($c->owner_user_id),
            'created_via' => $c->created_via,
            'contacts_count' => $c->contacts_count ?? $c->contacts()->count(),
            'stage_changed_at' => $c->stage_changed_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, int>  $breakdown
     * @param  array<int, string>  $nextStages
     * @return array<string, mixed>
     */
    public function companyDetail(Company $c, array $breakdown, array $nextStages): array
    {
        return $this->companyRow($c) + [
            'region_city' => $c->region_city,
            'employees' => $c->employees,
            'industry' => $c->industry,
            'has_offshore_center' => $c->has_offshore_center,
            'open_roles_6m' => $c->open_roles_6m,
            'client_regions' => $c->client_regions ?? [],
            'languages' => $c->languages ?? [],
            'source' => $c->source,
            'export_contract_usd' => $c->export_contract_usd,
            'parent_revenue_usd' => $c->parent_revenue_usd,
            'sanctions_status' => $c->sanctions_status,
            'icp_breakdown' => $breakdown,
            'next_stages' => $nextStages,
        ];
    }

    /** @return array<string, mixed> */
    public function contact(Contact $c): array
    {
        return [
            'id' => $c->id,
            'company_id' => $c->company_id,
            'full_name' => $c->full_name,
            'title' => $c->title,
            'role_type' => $c->role_type,
            'email' => $c->email,
            'email_status' => $c->email_status,
            'linkedin_url' => $c->linkedin_url,
            'language' => $c->language,
            'do_not_contact' => (bool) $c->do_not_contact,
            'unsubscribed_at' => $c->unsubscribed_at?->toIso8601String(),
            'active' => $c->isActive(),
        ];
    }

    /** Expects contact.company.country loaded. @return array<string, mixed> */
    public function message(Message $m): array
    {
        $contact = $m->contact;
        $company = $contact?->company;

        return [
            'id' => $m->id,
            'contact_id' => $m->contact_id,
            'contact_name' => $contact?->full_name,
            'contact_email' => $contact?->email,
            'company_id' => $company?->id,
            'company_name' => $company?->name,
            'country_code' => $company?->country_code,
            'tier' => $company?->tier,
            'sequence_step' => $m->sequence_step,
            'language' => $m->language,
            'subject' => $m->subject,
            'body' => $m->body,
            'body_hash' => $m->body_hash,
            'status' => $m->status,
            'approved_by_name' => $this->name($m->approved_by_user_id),
            'approved_at' => $m->approved_at?->toIso8601String(),
            'rejected_by_name' => $this->name($m->rejected_by_user_id),
            'rejected_at' => $m->rejected_at?->toIso8601String(),
            'reject_reason' => $m->reject_reason,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function touch(Touch $t): array
    {
        return [
            'id' => $t->id,
            'contact_id' => $t->contact_id,
            'channel' => $t->channel,
            'direction' => $t->direction,
            'summary' => $t->summary,
            'occurred_at' => $t->occurred_at?->toIso8601String(),
            'via' => $t->via,
            'actor_name' => $this->name($t->actor_user_id),
        ];
    }

    /** @return array<string, mixed> */
    public function audit(AuditEntry $a): array
    {
        return [
            'id' => $a->id,
            'action' => $a->action,
            'actor' => $a->actor,
            'via' => $a->via,
            'actor_name' => $this->name($a->actor_user_id),
            'payload' => $a->payload_json,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @return array<int, array<string, mixed>>
     */
    public function companyRows(Collection $companies): array
    {
        $this->preloadNames($companies->pluck('owner_user_id'));

        return $companies->map(fn (Company $c): array => $this->companyRow($c))->all();
    }
}
