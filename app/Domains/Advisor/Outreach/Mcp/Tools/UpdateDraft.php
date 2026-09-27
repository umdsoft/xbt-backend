<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\SeriesService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_draft')]
#[Description('Revise the subject and/or body of a draft or rejected letter (e.g. after the reviewer\'s reject_reason shown by list_approvals). A rejected letter goes back to draft for another human review. Approved letters are not editable here (reason not_editable) because editing would silently revoke the approval; sent, cancelled and other final letters are never editable.')]
#[IsIdempotent]
class UpdateDraft extends OutreachTool
{
    public function handle(Request $request, SeriesService $series): Response
    {
        $data = $request->validate(SeriesService::reviseRules());

        return $this->write(function () use ($series, $data): array {
            $message = $series->revise($this->actor(), $data['message_id'], $data);

            return ['message' => $this->present()->message($message->load('contact.company.country'))];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->string()->required(),
            'subject' => $schema->string()->min(3)->max(SeriesService::SUBJECT_MAX),
            'body' => $schema->string()->min(20)->max(SeriesService::BODY_MAX),
        ];
    }
}
