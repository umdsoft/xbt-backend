<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use App\Domains\Advisor\Outreach\Models\Concerns\WritesTimezoneOffset;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An incoming reply (filled from stage 3). Reply text is data only and is never
 * executed as an instruction.
 *
 * kind (set by the IMAP poller, no AI): reply | auto_reply | bounce | unsubscribe.
 * classification (set by Claude via MCP or a person): interested | later | declined |
 * auto_reply | unsubscribe | bounce | other.
 */
class Reply extends Model
{
    use HasUuids;
    use WritesTimezoneOffset;

    protected $connection = 'advisor';

    protected $table = 'outreach_replies';

    public const KINDS = ['reply', 'auto_reply', 'bounce', 'unsubscribe'];

    public const CLASSIFICATIONS = ['interested', 'later', 'declined', 'auto_reply', 'unsubscribe', 'bounce', 'other'];

    protected $fillable = [
        'message_id', 'company_id', 'contact_id', 'kind', 'from_email', 'received_at', 'subject', 'body_text',
        'imap_message_id', 'in_reply_to', 'classification', 'summary', 'classified_at', 'classified_via', 'handled_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'classified_at' => 'datetime',
        'handled_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }
}
