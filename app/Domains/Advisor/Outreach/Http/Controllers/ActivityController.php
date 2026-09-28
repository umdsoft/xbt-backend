<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Http\Controllers;

use App\Domains\Advisor\Outreach\Http\OutreachPresenter;
use App\Domains\Advisor\Outreach\Services\ContactService;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use App\Domains\Advisor\Outreach\Services\TouchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contacts and the interaction log of a lead. */
class ActivityController extends OutreachController
{
    public function __construct(
        OutreachGate $gate,
        OutreachPresenter $present,
        private readonly ContactService $contacts,
        private readonly TouchService $touches,
    ) {
        parent::__construct($gate, $present);
    }

    public function contact(Request $request): JsonResponse
    {
        $result = $this->contacts->upsert($this->actor($request), $request->validate(ContactService::rules()));

        return response()->json([
            'contact' => $this->present->contact($result['contact']),
            'created' => $result['created'],
        ], $result['created'] ? 201 : 200);
    }

    public function touch(Request $request): JsonResponse
    {
        $touch = $this->touches->log($this->actor($request), $request->validate(TouchService::rules()));

        return response()->json(['touch' => $this->present->touch($touch)], 201);
    }
}
