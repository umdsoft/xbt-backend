<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Http\Controllers;

use App\Domains\Advisor\Outreach\Http\OutreachPresenter;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Services\ApprovalService;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Approval queue (viloyat) and message editing. */
class ApprovalController extends OutreachController
{
    public function __construct(
        OutreachGate $gate,
        OutreachPresenter $present,
        private readonly ApprovalService $approvals,
    ) {
        parent::__construct($gate, $present);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeTo($request, OutreachGate::APPROVE);
        $f = $request->validate([
            'country' => ['nullable', 'string', 'size:2'],
            'tier' => ['nullable', Rule::in(['A', 'B', 'C'])],
            'owner' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Message::query()
            ->with('contact.company.country')
            ->where('status', Message::DRAFT)
            ->whereHas('contact.company', function (Builder $c) use ($f): void {
                $c->when($f['country'] ?? null, fn (Builder $q, string $v) => $q->where('country_code', strtoupper($v)))
                    ->when($f['tier'] ?? null, fn (Builder $q, string $v) => $q->where('tier', $v))
                    ->when($f['owner'] ?? null, fn (Builder $q, string $v) => $q->where('owner_user_id', $v));
            })
            ->orderBy('created_at')
            ->paginate($this->perPage($request));

        $messages = $page->getCollection();
        $this->present->preloadNames([...$messages->pluck('approved_by_user_id'), ...$messages->pluck('rejected_by_user_id')]);

        return response()->json([
            'data' => $messages->map(fn (Message $m) => $this->present->message($m))->all(),
            'meta' => $this->meta($page),
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['body_hash' => ['required', 'string', 'size:64']]);

        return $this->respond($this->approvals->approve($this->actor($request), $id, $data['body_hash']));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->respond($this->approvals->reject($this->actor($request), $id, $data['reason']));
    }

    public function edit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        return $this->respond($this->approvals->edit($this->actor($request), $id, $data['subject'], $data['body']));
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.ApprovalService::BULK_MAX],
            'items.*.id' => ['required', 'uuid'],
            'items.*.body_hash' => ['required', 'string', 'size:64'],
        ]);

        return response()->json($this->approvals->bulkApprove($this->actor($request), $data['items']));
    }

    public function bulkReject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.ApprovalService::BULK_MAX],
            'ids.*' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json($this->approvals->bulkReject($this->actor($request), $data['ids'], $data['reason']));
    }

    private function respond(Message $message): JsonResponse
    {
        return response()->json(['message' => $this->present->message($message->load('contact.company.country'))]);
    }
}
