<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Services\SeriesService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_series')]
#[Description('Create a letter series (1-3 steps) for one active contact as DRAFTS that wait for human approval in the web UI. Step 1 goes out first, step 2 four days and step 3 ten days after step 1 actually leaves (the server schedules; do not put dates in the text). Write in the given language (contact language, company/country language or English). Requirements: lead at stage verified, awaiting_approval or sent; tier A or B; contact active with an email that is not suppressed; no open series for this contact (reason already_open: revise it with update_draft instead). Refusals come back as reason not_draftable with context.check. A verified lead moves to awaiting_approval. This never approves or sends anything.')]
class CreateSeries extends OutreachTool
{
    public function handle(Request $request, SeriesService $series): Response
    {
        $data = $request->validate(SeriesService::rules());

        return $this->write(function () use ($series, $data): array {
            $result = $series->create($this->actor(), $data);
            $messages = $result['messages']->each(fn (Message $m) => $m->load('contact.company.country'));

            return [
                'series_id' => $result['series_id'],
                'lead_stage' => $result['company']->stage,
                'stage_moved' => $result['stage_moved'],
                'step_offset_days' => array_slice(SeriesService::STEP_OFFSET_DAYS, 0, $messages->count()),
                'messages' => $messages->map(fn (Message $m) => $this->present()->message($m))->all(),
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->string()->description('Contact id from upsert_contact / get_company.')->required(),
            'language' => $schema->string()->pattern('^[a-z]{2}$')->description('ISO 639-1 language of the letters, e.g. en, ru, tr, zh.')->required(),
            'steps' => $schema->array()->min(1)->max(SeriesService::MAX_STEPS)->items($schema->object([
                'subject' => $schema->string()->min(3)->max(SeriesService::SUBJECT_MAX)->required(),
                'body' => $schema->string()->min(20)->max(SeriesService::BODY_MAX)->required(),
            ]))->description('Letters in order: [first letter, follow-up, final follow-up].')->required(),
        ];
    }
}
