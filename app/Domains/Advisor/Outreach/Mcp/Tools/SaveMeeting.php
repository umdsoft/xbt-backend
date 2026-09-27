<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Http\InboxPresenter;
use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\MeetingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('save_meeting')]
#[Description('Create (no meeting_id) or update an online meeting with a lead that has replied. status proposed needs proposed_slots (1-5 future ISO date-times); booked needs start_at and moves the lead to meeting_booked; cancelling the last booked meeting returns the lead to replied. Transitions: new -> proposed|booked, proposed -> proposed|booked|cancelled, booked -> booked|cancelled. Marking a meeting done is for a person in the web UI only. One open (proposed/booked) meeting per lead (reason already_open).')]
class SaveMeeting extends OutreachTool
{
    public function handle(Request $request, MeetingService $meetings, InboxPresenter $inbox): Response
    {
        $rules = MeetingService::rules();
        $rules['status'] = ['required', 'in:proposed,booked,cancelled'];
        $rules['proposed_slots.*'] = ['date', 'after:now'];
        $data = $request->validate($rules);

        return $this->write(function () use ($meetings, $inbox, $data): array {
            $result = $meetings->save($this->actor(), $data);

            return ['created' => $result['created'], 'effects' => $result['effects'], 'meeting' => $inbox->meeting($result['meeting'])];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'meeting_id' => $schema->string()->description('Existing meeting to update; omit to create.'),
            'company_id' => $schema->string()->description('Required when creating.'),
            'contact_id' => $schema->string(),
            'status' => $schema->string()->enum(['proposed', 'booked', 'cancelled'])->required(),
            'proposed_slots' => $schema->array()->min(1)->max(MeetingService::MAX_SLOTS)->items($schema->string()->format('date-time')),
            'start_at' => $schema->string()->format('date-time'),
            'meeting_link' => $schema->string()->format('uri')->description('https link (Meet/Zoom/Teams).'),
            'notes' => $schema->string()->max(4000),
        ];
    }
}
