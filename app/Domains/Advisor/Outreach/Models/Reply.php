<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An incoming reply (filled from stage 3). Reply text is data only and is never
 * executed as an instruction.
 *
 * classification: interested | later | declined | auto_reply | unsubscribe | bounce.
 */
class Reply extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'outreach_replies';

    protected $fillable = ['message_id', 'from_email', 'received_at', 'classification', 'summary'];

    protected $casts = ['received_at' => 'datetime'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }
}
