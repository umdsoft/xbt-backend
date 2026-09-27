<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lead: a foreign IT company invited to open a branch in Xorazm.
 *
 * `icp_score` and `tier` are computed by IcpScorer and are never written from
 * input. `stage` changes only through StageMachine. `owner_user_id` is the
 * responsible advisor (auth.users.id).
 */
class Company extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'outreach_companies';

    protected $fillable = [
        'name', 'domain', 'country_code', 'region_city', 'owner_user_id',
        'employees', 'industry', 'has_offshore_center', 'open_roles_6m',
        'client_regions', 'languages', 'source', 'created_via',
        'export_contract_usd', 'parent_revenue_usd',
        'icp_score', 'tier', 'sanctions_status', 'stage', 'stage_changed_at', 'created_by',
    ];

    protected $casts = [
        'employees' => 'integer',
        'has_offshore_center' => 'boolean',
        'open_roles_6m' => 'integer',
        'client_regions' => 'array',
        'languages' => 'array',
        'export_contract_usd' => 'integer',
        'parent_revenue_usd' => 'integer',
        'icp_score' => 'integer',
        'stage_changed_at' => 'datetime',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'company_id');
    }

    /**
     * Interaction history. Not named `touches()`: that name is taken by
     * Eloquent's parent-timestamp feature.
     *
     * @return HasMany<Touch, $this>
     */
    public function touchHistory(): HasMany
    {
        return $this->hasMany(Touch::class, 'company_id');
    }

    /** @return HasMany<Meeting, $this> */
    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'company_id');
    }
}
