<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use App\Domains\Advisor\Outreach\Models\Concerns\WritesTimezoneOffset;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An email address or whole domain that must never be mailed again,
 * independent of whether its contact still exists (CLAUDE.md rule 5).
 */
class Suppression extends Model
{
    use HasUuids;
    use WritesTimezoneOffset;

    public const UPDATED_AT = null;

    public const REASONS = ['unsubscribed', 'hard_bounce', 'complaint', 'manual'];

    protected $connection = 'advisor';

    protected $table = 'outreach_suppressions';

    protected $fillable = ['email', 'domain', 'reason', 'source_message_id'];
}
