<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Sending;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DNS reader for the preflight checks.
 *
 * Queries PUBLIC DNS over HTTPS instead of the system resolver: on our
 * servers /etc/hosts maps mail names to LAN addresses (and the egress IP to
 * a local name), while Gmail/Microsoft see the public records — those are
 * what must be checked. Replaceable in tests.
 */
class Dns
{
    private const TYPES = ['A' => 1, 'MX' => 15, 'TXT' => 16, 'PTR' => 12];

    /** @return array<int, string> IPv4 addresses */
    public function a(string $host): array
    {
        return $this->query($host, 'A');
    }

    /** @return array<int, string> TXT strings (multi-part records joined) */
    public function txt(string $host): array
    {
        return array_map(
            static fn (string $v): string => implode('', array_map(
                static fn (string $p): string => stripslashes($p),
                preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $v, $m) ? $m[1] : [$v],
            )),
            $this->query($host, 'TXT'),
        );
    }

    /** @return array<int, string> MX target hosts */
    public function mx(string $domain): array
    {
        return array_values(array_map(
            static fn (string $v): string => strtolower(rtrim((string) preg_replace('/^\d+\s+/', '', $v), '.')),
            $this->query($domain, 'MX'),
        ));
    }

    /** Reverse DNS name of an IPv4 address, or null when there is no PTR. */
    public function ptr(string $ip): ?string
    {
        $arpa = implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
        $name = $this->query($arpa, 'PTR')[0] ?? null;

        return $name === null ? null : strtolower(rtrim($name, '.'));
    }

    /** @return array<int, string> answer data of the requested type */
    private function query(string $name, string $type): array
    {
        try {
            $res = Http::timeout(8)
                ->withHeaders(['Accept' => 'application/dns-json'])
                ->get((string) config('outreach.send.doh_url', 'https://cloudflare-dns.com/dns-query'), ['name' => $name, 'type' => $type]);
        } catch (Throwable) {
            return [];
        }

        $want = self::TYPES[$type];

        return array_values(array_map(
            static fn (array $a): string => (string) $a['data'],
            array_filter((array) ($res->json('Answer') ?? []), static fn ($a): bool => is_array($a) && ($a['type'] ?? null) === $want),
        ));
    }
}
