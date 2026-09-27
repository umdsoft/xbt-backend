<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Sending;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Letters arrive in the recipient's business hours: Mon–Fri, between
 * outreach.send.window_start_hour and window_end_hour in the recipient
 * country's time zone (PLAN-send.md §2).
 */
final class SendWindow
{
    public function isOpen(?string $timezone, Carbon $now): bool
    {
        $local = $now->copy()->setTimezone($this->zone($timezone));
        $start = (int) config('outreach.send.window_start_hour', 9);
        $end = (int) config('outreach.send.window_end_hour', 16);

        return $local->isWeekday() && $local->hour >= $start && $local->hour < $end;
    }

    private function zone(?string $timezone): string
    {
        $fallback = (string) config('outreach.send.default_recipient_timezone', 'Europe/Berlin');

        if ($timezone === null || $timezone === '') {
            return $fallback;
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (Throwable) {
            return $fallback;
        }
    }
}
