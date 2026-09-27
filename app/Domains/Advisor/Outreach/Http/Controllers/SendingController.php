<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Http\Controllers;

use App\Domains\Advisor\Outreach\Http\OutreachPresenter;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Sender;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use App\Domains\Advisor\Outreach\Services\Sending\SendingAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Sending dashboard and controls (viloyat). Contract: docs/outreach/API-contract.md "Sending". */
class SendingController extends OutreachController
{
    public function __construct(
        OutreachGate $gate,
        OutreachPresenter $present,
        private readonly SendingAdmin $admin,
    ) {
        parent::__construct($gate, $present);
    }

    public function overview(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $overview = $this->admin->overview($actor, Carbon::now());

        $unknown = Message::query()->with('contact.company.country')->where('status', Message::SEND_UNKNOWN)
            ->orderBy('claimed_at')->limit(50)->get();

        return response()->json($overview + [
            'unknown' => $unknown->map(fn (Message $m) => $this->present->message($m) + [
                'last_error' => $m->last_error,
                'claimed_at' => $m->claimed_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function pause(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->admin->pause($this->actor($request), $data['reason']);

        return $this->overview($request);
    }

    public function resume(Request $request): JsonResponse
    {
        $this->admin->resume($this->actor($request));

        return $this->overview($request);
    }

    public function resetBreaker(Request $request): JsonResponse
    {
        $this->admin->resetBreaker($this->actor($request));

        return $this->overview($request);
    }

    public function addSender(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique(Sender::class, 'email')],
            'display_name' => ['required', 'string', 'max:120'],
            'warmup_started_on' => ['nullable', 'date'],
            'daily_cap_max' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $sender = $this->admin->addSender($this->actor($request), $data);

        return response()->json(['sender' => ['id' => $sender->id, 'email' => $sender->email]], 201);
    }

    public function updateSender(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'daily_cap_max' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'display_name' => ['sometimes', 'string', 'max:120'],
            'resume' => ['sometimes', 'boolean'],
        ]);

        $sender = $this->admin->updateSender($this->actor($request), $id, $data);

        return response()->json(['sender' => ['id' => $sender->id, 'active' => $sender->active, 'paused_at' => $sender->paused_at?->toIso8601String()]]);
    }

    public function unqueue(Request $request, string $id): JsonResponse
    {
        $message = $this->admin->unqueue($this->actor($request), $id);

        return response()->json(['message' => $this->present->message($message->load('contact.company.country'))]);
    }

    public function resolveUnknown(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['outcome' => ['required', Rule::in([Message::SENT, Message::FAILED])]]);
        $message = $this->admin->resolveUnknown($this->actor($request), $id, $data['outcome']);

        return response()->json(['message' => $this->present->message($message->load('contact.company.country'))]);
    }
}
