<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Sending;

use App\Domains\Advisor\Outreach\Models\Sender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * "Is it safe to switch OUTREACH_SEND_MODE to live?" (PLAN-send.md §10a/10b).
 *
 * Every check returns pass | warn | fail with a human explanation. Any fail
 * means: do not go live. The same list is shown in the CLI and can be shown
 * in the UI later.
 */
final class SendPreflight
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(private readonly Dns $dns) {}

    /** @return array<int, array{check: string, status: string, detail: string}> */
    public function run(): array
    {
        $results = [];
        $add = function (string $check, string $status, string $detail) use (&$results): void {
            $results[] = ['check' => $check, 'status' => $status, 'detail' => $detail];
        };

        $this->identity($add);
        $senders = $this->senders($add);
        $this->heloChain($add);
        foreach ($this->domains($senders) as $domain) {
            $this->domainRecords($domain, $add);
        }
        $this->smtp($add);

        return $results;
    }

    /** @param  array<int, array{status: string}>  $results */
    public static function passed(array $results): bool
    {
        return ! in_array(self::FAIL, array_column($results, 'status'), true);
    }

    private function identity(callable $add): void
    {
        $id = (array) config('outreach.send.identity');
        $envKeys = ['name' => 'OUTREACH_SENDER_NAME', 'organization' => 'OUTREACH_SENDER_ORG', 'address' => 'OUTREACH_SENDER_ADDRESS'];
        $missing = array_values(array_intersect_key($envKeys, array_filter($envKeys, fn ($_, $k) => empty($id[$k] ?? null), ARRAY_FILTER_USE_BOTH)));

        $add('sender identity', $missing === [] ? self::PASS : self::FAIL, $missing === []
            ? "{$id['name']}, {$id['organization']}"
            : 'Missing '.implode(', ', $missing).' (required by SPEC §4.3 in every letter).');
    }

    /** @return Collection<int, Sender> */
    private function senders(callable $add)
    {
        $senders = Sender::query()->where('active', true)->get();
        $usable = $senders->filter->isUsable();

        $add('mailbox pool', $usable->isNotEmpty() ? self::PASS : self::FAIL, $usable->isNotEmpty()
            ? $usable->count().' usable mailbox(es): '.$usable->pluck('email')->take(5)->implode(', ')
            : 'No active, unpaused mailbox in outreach_senders.');

        return $usable;
    }

    /** HELO name -> A -> egress IP -> PTR -> HELO name (forward-confirmed reverse DNS). */
    private function heloChain(callable $add): void
    {
        $helo = strtolower((string) config('outreach.send.helo_name'));
        $ip = (string) config('outreach.send.egress_ip');

        if ($helo === '' || $ip === '') {
            $add('HELO / reverse DNS', self::WARN, 'Set OUTREACH_HELO_NAME and OUTREACH_EGRESS_IP to check the FCrDNS chain.');

            return;
        }

        $a = $this->dns->a($helo);
        $add("A {$helo}", in_array($ip, $a, true) ? self::PASS : self::FAIL, $a === []
            ? 'No A record. Gmail/Microsoft reject servers whose HELO name does not resolve.'
            : 'Resolves to '.implode(', ', $a).(in_array($ip, $a, true) ? '' : " (expected {$ip})."));

        $ptr = $this->dns->ptr($ip);
        $add("PTR {$ip}", $ptr === $helo ? self::PASS : self::FAIL, match (true) {
            $ptr === null => "No PTR. Ask the IP provider (One-Net) for {$ip} -> {$helo}.",
            $ptr !== $helo => "PTR is {$ptr}, expected {$helo}.",
            default => $ptr,
        });
    }

    /** @param  Collection<int, Sender>  $senders
     * @return array<int, string> */
    private function domains($senders): array
    {
        return $senders->map(fn (Sender $s) => strtolower(substr((string) strrchr($s->email, '@'), 1)))
            ->filter()->unique()->values()->all();
    }

    private function domainRecords(string $domain, callable $add): void
    {
        $ip = (string) config('outreach.send.egress_ip');

        $spf = collect($this->dns->txt($domain))->first(fn (string $t) => str_starts_with(strtolower($t), 'v=spf1'));
        $spfOk = $spf !== null && ($ip === '' || str_contains($spf, 'ip4:'.$ip) || $this->mxCovers($domain, $spf, $ip));
        $add("SPF {$domain}", $spfOk ? self::PASS : self::FAIL, $spf === null ? 'No SPF record.' : $spf.($spfOk ? '' : " (does not authorise {$ip})"));

        $selector = (string) config('outreach.send.dkim_selector', 'dkim');
        $dkim = collect($this->dns->txt("{$selector}._domainkey.{$domain}"))->first(fn (string $t) => str_contains($t, 'p='));
        $add("DKIM {$selector}._domainkey.{$domain}", $dkim !== null ? self::PASS : self::FAIL, $dkim !== null ? 'Key published.' : 'No DKIM key; create it in Mailcow and publish the TXT record.');

        $dmarc = collect($this->dns->txt("_dmarc.{$domain}"))->first(fn (string $t) => str_starts_with(strtolower($t), 'v=dmarc1'));
        $add("DMARC {$domain}", $dmarc !== null ? self::PASS : self::FAIL, $dmarc ?? 'No DMARC record (required by Gmail/Yahoo for bulk senders).');
    }

    /** `mx` mechanism: does one of the domain's MX hosts resolve to the egress IP? */
    private function mxCovers(string $domain, string $spf, string $ip): bool
    {
        if (! preg_match('/(^|\s)[+]?mx(\s|$)/i', $spf)) {
            return false;
        }

        foreach ($this->dns->mx($domain) as $host) {
            if (in_array($ip, $this->dns->a($host), true)) {
                return true;
            }
        }

        return false;
    }

    /** Can we open an authenticated SMTP session to Mailcow? (No mail is sent.) */
    private function smtp(callable $add): void
    {
        try {
            $transport = Mail::mailer('outreach')->getSymfonyTransport();
            if (method_exists($transport, 'start')) {
                $transport->start();
                $transport->stop();
            }
            $add('SMTP login (outreach mailer)', self::PASS, (string) config('mail.mailers.outreach.host').':'.(string) config('mail.mailers.outreach.port'));
        } catch (Throwable $e) {
            $add('SMTP login (outreach mailer)', self::FAIL, mb_substr($e->getMessage(), 0, 300));
        }
    }
}
