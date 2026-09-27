<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Touch;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Interaction log (email/call/LinkedIn/meeting notes). Append-only by design:
 * there is no update or delete path.
 */
final class TouchService
{
    public function __construct(
        private readonly OutreachGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'channel' => ['required', Rule::in(Touch::CHANNELS)],
            'direction' => ['required', Rule::in(Touch::DIRECTIONS)],
            'summary' => ['required', 'string', 'max:4000'],
            'occurred_at' => ['required', 'date', 'before_or_equal:+1 day'],
        ];
    }

    /** @param  array<string, mixed>  $data  already shape-validated */
    public function log(Actor $actor, array $data): Touch
    {
        $this->gate->require($actor, OutreachGate::MANAGE);

        return DB::connection('advisor')->transaction(function () use ($actor, $data): Touch {
            $company = $this->gate->company($actor, (string) $data['company_id']);

            $contactId = $data['contact_id'] ?? null;
            if ($contactId !== null && ! Contact::query()->where('company_id', $company->id)->whereKey($contactId)->exists()) {
                throw new RuleViolation(RuleViolation::NOT_FOUND, 'Контакт бу компанияга тегишли эмас.');
            }

            $touch = Touch::query()->create([
                'company_id' => $company->id,
                'contact_id' => $contactId,
                'channel' => $data['channel'],
                'direction' => $data['direction'],
                'summary' => $data['summary'],
                'occurred_at' => $data['occurred_at'],
                'actor_user_id' => $actor->userId(),
                'via' => $actor->via,
            ]);

            $this->audit->log($actor, 'touch.logged', 'company', $company->id, [
                'touch_id' => $touch->id,
                'channel' => $touch->channel,
                'direction' => $touch->direction,
            ]);

            return $touch;
        });
    }
}
