<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use App\Domains\Advisor\Outreach\Models\Concerns\WritesTimezoneOffset;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message in a contact's sequence (steps 1-3). Drafts wait in the approval
 * queue; only a viloyat advisor approves them in the UI.
 *
 * On approval `body_hash = sha256(subject + "\n" + body)` is stored; editing an
 * approved message returns it to draft. Stage 3 sends only when the current
 * hash still matches.
 */
class Message extends Model
{
    use HasUuids;
    use WritesTimezoneOffset;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SENT = 'sent';

    public const BOUNCED = 'bounced';

    public const REPLIED = 'replied';

    public const CANCELLED = 'cancelled';

    /** Claimed by a send worker; never touched by anything else meanwhile. */
    public const SENDING = 'sending';

    /** Permanent SMTP failure or retries exhausted. */
    public const FAILED = 'failed';

    /** Connection dropped after DATA: delivery unknown, a person decides (never auto-resent). */
    public const SEND_UNKNOWN = 'send_unknown';

    protected $connection = 'advisor';

    protected $table = 'outreach_messages';

    protected $fillable = [
        'contact_id', 'sequence_step', 'language', 'subject', 'body', 'body_hash', 'status',
        'approved_by_user_id', 'approved_at', 'rejected_by_user_id', 'rejected_at', 'reject_reason',
        'scheduled_for', 'sent_at', 'smtp_message_id', 'created_by', 'series_id',
    ];

    protected $casts = [
        'sequence_step' => 'integer',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'claimed_at' => 'datetime',
        'send_attempts' => 'integer',
    ];

    /**
     * Invariants that hold no matter which code path saves the message:
     *  - body_hash always matches subject+body;
     *  - changing the text of an approved message drops the approval, so an
     *    approved hash can never cover text nobody approved (CLAUDE.md rule 1).
     */
    protected static function booted(): void
    {
        static::saving(static function (self $message): void {
            $textChanged = $message->isDirty(['subject', 'body']);

            if ($message->exists && $textChanged && $message->getOriginal('status') === self::APPROVED) {
                $message->status = self::DRAFT;
                $message->approved_by_user_id = null;
                $message->approved_at = null;
            }

            $message->body_hash = $message->currentHash();
        });
    }

    public static function hashOf(string $subject, string $body): string
    {
        return hash('sha256', $subject."\n".$body);
    }

    public function currentHash(): string
    {
        return self::hashOf((string) $this->subject, (string) $this->body);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return HasMany<Reply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(Reply::class, 'message_id');
    }
}
