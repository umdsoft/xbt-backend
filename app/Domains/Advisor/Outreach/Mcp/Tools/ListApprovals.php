<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_approvals')]
#[Description('Read-only view of messages in the human approval workflow: drafts waiting for approval, rejected drafts with the reviewer\'s reason (to revise), or approved ones. Approving is only possible by a person in the web UI; there is no tool for it.')]
#[IsReadOnly]
class ListApprovals extends OutreachTool
{
    private const MAX_LIMIT = 100;

    public function handle(Request $request, OutreachGate $gate): Response
    {
        $f = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', [Message::DRAFT, Message::REJECTED, Message::APPROVED])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        return $this->read(function () use ($gate, $f): array {
            $gate->require($this->actor(), OutreachGate::VIEW);
            $companies = $gate->scope(Company::query(), $this->actor())->select('id');

            $messages = Message::query()->with('contact.company.country')
                ->where('status', $f['status'] ?? Message::DRAFT)
                ->whereHas('contact', fn (Builder $c) => $c->whereIn('company_id', $companies))
                ->orderBy('created_at')->limit($f['limit'] ?? 50)->get();

            $this->present()->preloadNames([...$messages->pluck('approved_by_user_id'), ...$messages->pluck('rejected_by_user_id')]);

            return ['messages' => $messages->map(fn (Message $m) => $this->present()->message($m))->all()];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum([Message::DRAFT, Message::REJECTED, Message::APPROVED])->default(Message::DRAFT),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->default(50),
        ];
    }
}
