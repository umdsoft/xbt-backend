<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Http\Controllers;

use App\Domains\Advisor\Outreach\Http\InboxPresenter;
use App\Domains\Advisor\Outreach\Http\OutreachPresenter;
use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Meeting;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Services\MeetingService;
use App\Domains\Advisor\Outreach\Services\OptOutService;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use App\Domains\Advisor\Outreach\Services\SanctionsService;
use App\Domains\Advisor\Outreach\Services\SeriesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Letter series, meetings, opt-out and sanctions marks for the advisor UI —
 * the same services the MCP tools use, acting as a UI actor.
 */
class MailController extends OutreachController
{
    public function __construct(
        OutreachGate $gate,
        OutreachPresenter $present,
        private readonly InboxPresenter $inbox,
    ) {
        parent::__construct($gate, $present);
    }

    public function storeSeries(Request $request, SeriesService $series): JsonResponse
    {
        $result = $series->create($this->actor($request), $request->validate(SeriesService::rules()));
        $messages = $result['messages']->each(fn (Message $m) => $m->load('contact.company.country'));

        return response()->json([
            'series_id' => $result['series_id'],
            'stage_moved' => $result['stage_moved'],
            'messages' => $messages->map(fn (Message $m) => $this->present->message($m))->all(),
        ], 201);
    }

    public function meetings(Request $request): JsonResponse
    {
        $actor = $this->authorizeTo($request, OutreachGate::VIEW);
        $f = $request->validate([
            'company_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(MeetingService::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Meeting::query()
            ->whereIn('company_id', $this->gate->scope(Company::query(), $actor)->select('id'))
            ->when($f['company_id'] ?? null, fn (Builder $q, string $v) => $q->where('company_id', $v))
            ->when($f['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->orderByRaw('start_at desc nulls last')->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Meeting $m) => $this->inbox->meeting($m))->all(),
            'meta' => $this->meta($page),
        ]);
    }

    public function storeMeeting(Request $request, MeetingService $meetings): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid']] + MeetingService::rules());
        unset($data['meeting_id']);

        return $this->meetingResponse($meetings->save($this->actor($request), $data), 201);
    }

    public function updateMeeting(Request $request, MeetingService $meetings, string $id): JsonResponse
    {
        $data = $request->validate(array_diff_key(MeetingService::rules(), ['meeting_id' => 1, 'company_id' => 1]));

        return $this->meetingResponse($meetings->save($this->actor($request), ['meeting_id' => $id] + $data));
    }

    public function unsubscribe(Request $request, OptOutService $optOut, string $id): JsonResponse
    {
        $result = $optOut->unsubscribe($this->actor($request), $id);

        return response()->json([
            'contact' => $this->present->contact($result['contact']),
            'already' => $result['already'],
            'suppressed' => $result['suppressed'],
        ]);
    }

    public function sanctions(Request $request, SanctionsService $sanctions, string $id): JsonResponse
    {
        $data = $request->validate(SanctionsService::rules());
        $company = $sanctions->mark($this->actor($request), $id, $data['status'], $data['source']);

        return response()->json(['company' => $this->present->companyRow($company->load('country')) + [
            'sanctions_status' => $company->sanctions_status,
            'sanctions_checked_at' => $company->sanctions_checked_at?->toIso8601String(),
            'sanctions_source' => $company->sanctions_source,
        ]]);
    }

    /** @param  array{meeting: Meeting, created: bool, effects: array<int, string>}  $result */
    private function meetingResponse(array $result, int $status = 200): JsonResponse
    {
        return response()->json(['meeting' => $this->inbox->meeting($result['meeting']), 'effects' => $result['effects']], $status);
    }
}
