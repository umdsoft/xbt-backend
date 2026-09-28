<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use App\Domains\Advisor\Outreach\Models\Concerns\WritesTimezoneOffset;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One send attempt or guard decision for a message. */
class SendLogEntry extends Model
{
    use HasUuids;
    use WritesTimezoneOffset;

    public const UPDATED_AT = null;

    protected $connection = 'advisor';

    protected $table = 'outreach_send_log';

    protected $fillable = ['message_id', 'sender_id', 'outcome', 'reason', 'smtp_code', 'detail'];

    protected $casts = ['smtp_code' => 'integer'];
}
