<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Support;

/**
 * Canonical forms used for uniqueness and duplicate checks.
 */
final class Normalize
{
    /**
     * "https://WWW.Acme.com:443/about?x=1" -> "acme.com". Returns null when the
     * result is not a plausible hostname.
     */
    public static function domain(string $input): ?string
    {
        $d = strtolower(trim($input));
        $d = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $d); // scheme
        $d = (string) preg_replace('#[/?\#].*$#', '', $d);              // path, query, fragment
        $d = (string) preg_replace('#^[^@]*@#', '', $d);                // userinfo
        $d = (string) preg_replace('#:\d+$#', '', $d);                  // port
        $d = rtrim($d, '.');

        if (str_starts_with($d, 'www.')) {
            $d = substr($d, 4);
        }

        $valid = preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $d) === 1;

        return $valid ? $d : null;
    }

    public static function email(string $input): string
    {
        return strtolower(trim($input));
    }
}
