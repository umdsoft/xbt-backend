<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Who may see and change which lead (PLAN-crm.md §4).
 *
 * viloyat: every lead (permission `outreach.all`, covered by `*`).
 * tuman:   only leads it owns. Other advisors' leads answer 404, not 403, so
 *          their existence is not revealed.
 * system:  unrestricted (internal jobs, no user).
 */
final class OutreachGate
{
    public const VIEW = 'outreach.view';

    public const MANAGE = 'outreach.manage';

    public const APPROVE = 'outreach.approve';

    public const MCP = 'outreach.mcp';

    public const ALL_LEADS = 'outreach.all';

    public function __construct(private readonly AdvisorAccess $access) {}

    public function can(User $user, string $permission): bool
    {
        return $this->access->can($user, $permission);
    }

    public function seesAll(?User $user): bool
    {
        return $user === null || $this->access->can($user, self::ALL_LEADS);
    }

    public function actorSeesAll(Actor $actor): bool
    {
        return $actor->user === null || $this->seesAll($actor->user);
    }

    public function require(Actor $actor, string $permission): void
    {
        if ($actor->user !== null && ! $this->access->can($actor->user, $permission)) {
            throw new RuleViolation(RuleViolation::FORBIDDEN, 'Бу амал учун ҳуқуқ йўқ.');
        }
    }

    public function canAccess(Actor $actor, Company $company): bool
    {
        return $this->actorSeesAll($actor) || $company->owner_user_id === $actor->userId();
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function scope(Builder $query, Actor $actor): Builder
    {
        return $this->actorSeesAll($actor)
            ? $query
            : $query->where('owner_user_id', $actor->userId());
    }

    /** Load a lead the actor may access, or fail with not_found. */
    public function company(Actor $actor, string $id, bool $lock = false): Company
    {
        $query = $this->scope(Company::query(), $actor)->whereKey($id);
        $company = Str::isUuid($id) ? ($lock ? $query->lockForUpdate() : $query)->first() : null;

        if ($company === null) {
            throw new RuleViolation(RuleViolation::NOT_FOUND, 'Лид топилмади.');
        }

        return $company;
    }
}
