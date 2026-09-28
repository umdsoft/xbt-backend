<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Http\Controllers;

use App\Domains\Advisor\Outreach\Http\OutreachPresenter;
use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Services\CompanyService;
use App\Domains\Advisor\Outreach\Services\DedupeService;
use App\Domains\Advisor\Outreach\Services\IcpScorer;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use App\Domains\Advisor\Outreach\Services\StageMachine;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends OutreachController
{
    private const AUDIT_LIMIT = 100;

    public function __construct(
        OutreachGate $gate,
        OutreachPresenter $present,
        private readonly CompanyService $companies,
        private readonly StageMachine $stages,
        private readonly DedupeService $dedupe,
        private readonly IcpScorer $scorer,
    ) {
        parent::__construct($gate, $present);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->authorizeTo($request, OutreachGate::VIEW);
        $f = $request->validate([
            'country' => ['nullable', 'string', 'size:2'],
            'wave' => ['nullable', 'string', 'max:16'],
            'stage' => ['nullable', Rule::in(Stage::all())],
            'tier' => ['nullable', Rule::in(['A', 'B', 'C'])],
            'owner' => ['nullable', 'uuid'],
            'source' => ['nullable', Rule::in(['claude', 'manual'])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->gate->scope(Company::query(), $actor)
            ->with('country')->withCount('contacts')
            ->when($f['country'] ?? null, fn (Builder $q, string $v) => $q->where('country_code', strtoupper($v)))
            ->when($f['wave'] ?? null, fn (Builder $q, string $v) => $q->whereHas('country', fn (Builder $c) => $c->where('wave', $v)))
            ->when($f['stage'] ?? null, fn (Builder $q, string $v) => $q->where('stage', $v))
            ->when($f['tier'] ?? null, fn (Builder $q, string $v) => $q->where('tier', $v))
            ->when(($f['owner'] ?? null) && $this->gate->actorSeesAll($actor), fn (Builder $q) => $q->where('owner_user_id', $f['owner']))
            ->when($f['source'] ?? null, fn (Builder $q, string $v) => $q->where('created_via', $v === 'claude' ? Stage::VIA_MCP : Stage::VIA_UI))
            ->when($f['q'] ?? null, function (Builder $q, string $v): void {
                $like = '%'.addcslashes($v, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('name', 'ilike', $like)->orWhere('domain', 'ilike', $like));
            })
            ->orderByDesc('icp_score')->orderByDesc('created_at');

        $page = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => $this->present->companyRows($page->getCollection()),
            'meta' => $this->meta($page),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeTo($request, OutreachGate::VIEW);
        $company = $this->gate->company($actor, $id);
        $company->load('country')->loadCount('contacts');

        $contacts = $company->contacts()->orderBy('created_at')->get();
        $messages = Message::query()->with('contact.company.country')
            ->whereIn('contact_id', $contacts->pluck('id'))
            ->orderByDesc('created_at')->get();
        $touches = $company->touchHistory()->orderByDesc('occurred_at')->get();
        $audit = AuditEntry::query()
            ->whereIn('entity_id', [$company->id, ...$contacts->pluck('id'), ...$messages->pluck('id')])
            ->orderByDesc('created_at')->limit(self::AUDIT_LIMIT)->get();

        $this->present->preloadNames([
            $company->owner_user_id,
            ...$touches->pluck('actor_user_id'), ...$audit->pluck('actor_user_id'),
            ...$messages->pluck('approved_by_user_id'), ...$messages->pluck('rejected_by_user_id'),
        ]);

        return response()->json([
            'company' => $this->present->companyDetail(
                $company,
                $this->scorer->score($company, $contacts)['breakdown'],
                Stage::nextFor($company->stage, Stage::VIA_UI),
            ),
            'contacts' => $contacts->map(fn (Contact $c) => $this->present->contact($c))->all(),
            'messages' => $messages->map(fn (Message $m) => $this->present->message($m))->all(),
            'touches' => $touches->map(fn ($t) => $this->present->touch($t))->all(),
            'audit' => $audit->map(fn (AuditEntry $a) => $this->present->audit($a))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(CompanyService::rules());

        $result = $this->companies->upsert($actor, $data);

        return response()->json([
            'company' => $this->present->companyRow($result['company']->load('country')),
            'created' => $result['created'],
        ], $result['created'] ? 201 : 200);
    }

    public function stage(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $company = $this->stages->move($this->actor($request), $id, $data['to'], $data['reason'] ?? null);

        return response()->json(['company' => $this->present->companyRow($company->load('country'))]);
    }

    public function owner(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['owner_user_id' => ['required', 'uuid']]);

        $company = $this->companies->reassignOwner($this->actor($request), $id, $data['owner_user_id']);

        return response()->json(['company' => $this->present->companyRow($company->load('country'))]);
    }

    public function dedupe(Request $request): JsonResponse
    {
        $actor = $this->authorizeTo($request, OutreachGate::VIEW);
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:255', 'required_without:email'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->dedupe->check($actor, $data['domain'] ?? null, $data['email'] ?? null));
    }
}
