<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Http\InboxPresenter;
use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Meeting;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Services\IcpScorer;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use App\Domains\Advisor\Outreach\Services\ReplyService;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_company')]
#[Description('Everything recorded about one lead: fields, ICP breakdown, sanctions check, stages you may move it to, contacts, letters (with series_id and status), meetings and reply counts. Use get_thread for the conversation itself.')]
#[IsReadOnly]
class GetCompany extends OutreachTool
{
    public function handle(Request $request, OutreachGate $gate, IcpScorer $scorer, ReplyService $replies, InboxPresenter $inbox): Response
    {
        $f = $request->validate(['company_id' => ['required', 'uuid']]);

        return $this->read(function () use ($gate, $scorer, $replies, $inbox, $f): array {
            $gate->require($this->actor(), OutreachGate::VIEW);
            $company = $gate->company($this->actor(), $f['company_id'])->load('country')->loadCount('contacts');
            $contacts = $company->contacts()->orderBy('created_at')->get();
            $messages = Message::query()->with('contact.company.country')
                ->whereIn('contact_id', $contacts->pluck('id'))
                ->orderBy('created_at')->get();
            $companyReplies = $replies->scope($this->actor())->where('company_id', $company->id);

            $this->present()->preloadNames([
                $company->owner_user_id,
                ...$messages->pluck('approved_by_user_id'), ...$messages->pluck('rejected_by_user_id'),
            ]);

            return [
                'company' => $this->present()->companyDetail(
                    $company,
                    $scorer->score($company, $contacts)['breakdown'],
                    Stage::nextFor($company->stage, Stage::VIA_MCP),
                ),
                'contacts' => $contacts->map(fn (Contact $c) => $this->present()->contact($c))->all(),
                'messages' => $messages->map(fn (Message $m) => $this->present()->message($m))->all(),
                'meetings' => $company->meetings()->orderBy('created_at')->get()->map(fn (Meeting $m) => $inbox->meeting($m))->all(),
                'replies' => [
                    'total' => (clone $companyReplies)->count(),
                    'unclassified' => (clone $companyReplies)->whereNull('classification')->count(),
                ],
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->required(),
        ];
    }
}
