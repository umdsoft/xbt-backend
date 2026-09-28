<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Sending;

/**
 * Thin DNS reader so the preflight checks can be tested with fixed answers.
 */
class Dns
{
    /** @return array<int, string> IPv4 addresses */
    public function a(string $host): array
    {
        return array_values(array_filter(array_map(
            static fn (array $r): ?string => $r['ip'] ?? null,
            @dns_get_record($host, DNS_A) ?: [],
        )));
    }

    /** @return array<int, string> TXT strings (multi-part records joined) */
    public function txt(string $host): array
    {
        return array_values(array_map(
            static fn (array $r): string => isset($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? ''),
            @dns_get_record($host, DNS_TXT) ?: [],
        ));
    }

    /** @return array<int, string> MX target hosts */
    public function mx(string $domain): array
    {
        return array_values(array_map(
            static fn (array $r): string => strtolower((string) ($r['target'] ?? '')),
            @dns_get_record($domain, DNS_MX) ?: [],
        ));
    }

    /** Reverse DNS name of an IPv4 address, or null when there is no PTR. */
    public function ptr(string $ip): ?string
    {
        $name = @gethostbyaddr($ip);

        return $name === false || $name === $ip ? null : strtolower(rtrim($name, '.'));
    }
}
