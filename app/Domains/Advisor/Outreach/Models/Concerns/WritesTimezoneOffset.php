<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models\Concerns;

/**
 * Serialize datetimes WITH their UTC offset.
 *
 * Laravel's default 'Y-m-d H:i:s' has no offset, so PostgreSQL reads it in the
 * session time zone (Asia/Tashkent on our servers) while the app writes UTC:
 * every timestamptz value would be stored 5 hours early. With the offset the
 * instant is exact whatever the session zone is. Reading still works for
 * naive `timestamp` columns (Laravel falls back to a lenient parse).
 */
trait WritesTimezoneOffset
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:sP';
    }
}
