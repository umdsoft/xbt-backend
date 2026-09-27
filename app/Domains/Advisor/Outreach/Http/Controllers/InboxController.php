<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Http\Controllers;

use App\Domains\Advisor\Outreach\Http\InboxPresenter;
use App\Domains\Advisor\Outreach\Http\OutreachPresenter;
use App\Domains\Advisor\Outreach\Models\Reply;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use App\Domains\Advisor\Outreach\Services\ReplyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Incoming mail inbox and conversation thread for the advisor UI. Reply text
 * is returned under untrusted_* keys and must be rendered escaped.
 */
class InboxController extends OutreachController
{
    public function __construct(
        OutreachGate $gate,
        OutreachPresenter $present,
        private readonly ReplyService $replies,
        private readonly InboxPresenter $inbox,
    ) {
        parent::__construct($gate, $present);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->authorizeTo($request, OutreachGate::VIEW);
        $f = $request->validate([
            'unclassified' => ['nullable', 'boolean'],
            'needs_attention' => ['nullable', 'boolean'],
            'kind' => ['nullable', Rule::in(Reply::KINDS)],
            'classification' => ['nullable', Rule::in(Reply::CLASSIFICATIONS)],
            'company_id' => ['nullable', 'uuid'],
            'since' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->replies->scope($actor)
            ->when($request->boolean('unclassified'), fn (Builder $q) => $q->whereNull('classification'))
            ->when($request->boolean('needs_attention'), fn (Builder $q) => $q->where('classification', 'interested')->whereNull('handled_at'))
            ->when($f['kind'] ?? null, fn (Builder $q, string $v) => $q->where('kind', $v))
            ->when($f['classification'] ?? null, fn (Builder $q, string $v) => $q->where('classification', $v))
            ->when($f['company_id'] ?? null, fn (Builder $q, string $v) => $q->where('company_id', $v))
            ->when($f['since'] ?? null, fn (Builder $q, string $v) => $q->where('received_at', '>=', $v))
            ->orderByDesc('received_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => $this->inbox->replies($page->getCollection()),
            'meta' => $this->meta($page),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeTo($request, OutreachGate::VIEW);

        return response()->json(['reply' => $this->inbox->replies(collect([$this->replies->find($actor, $id)]))[0]]);
    }

    public function classify(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(ReplyService::classifyRules());
        $result = $this->replies->classify($this->actor($request), $id, $data['classification'], $data['summary'] ?? null);

        return response()->json([
            'reply' => $this->inbox->replies(collect([$result['reply']]))[0],
            'changed' => $result['changed'],
            'effects' => $result['effects'],
        ]);
    }

    public function handled(Request $request, string $id): JsonResponse
    {
        $reply = $this->replies->markHandled($this->actor($request), $id);

        return response()->json(['reply' => $this->inbox->replies(collect([$reply]))[0]]);
    }

    public function thread(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeTo($request, OutreachGate::VIEW);
        $f = $request->validate(['contact_id' => ['nullable', 'uuid']]);

        return response()->json($this->inbox->thread($this->replies->thread($actor, $id, $f['contact_id'] ?? null)));
    }
}
