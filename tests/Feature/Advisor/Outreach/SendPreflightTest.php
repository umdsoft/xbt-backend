<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\Sender;
use App\Domains\Advisor\Outreach\Services\Sending\Dns;
use App\Domains\Advisor\Outreach\Services\Sending\SendPreflight;

/** Readiness checks before live sending, with fixed DNS answers. */
class SendPreflightTest extends OutreachTestCase
{
    private const IP = '203.0.113.10';

    protected function setUp(): void
    {
        parent::setUp();
        Sender::query()->update(['active' => false]);   // ignore dev-DB mailboxes

        config([
            'mail.mailers.outreach' => ['transport' => 'array'],
            'outreach.send.egress_ip' => self::IP,
            'outreach.send.helo_name' => 'mail.example.test',
            'outreach.send.dkim_selector' => 'dkim',
            'outreach.send.identity' => ['name' => 'N', 'title' => 'T', 'organization' => 'O', 'address' => 'A'],
        ]);
        Sender::query()->create(['email' => 'team@send.example.test', 'mailer' => 'outreach', 'display_name' => 'Team', 'warmup_started_on' => '2026-09-01']);
    }

    /** @param  array<string, mixed>  $answers */
    private function dns(array $answers): void
    {
        $this->app->instance(Dns::class, new class($answers) extends Dns
        {
            public function __construct(private array $answers) {}

            public function a(string $host): array
            {
                return $this->answers['a'][$host] ?? [];
            }

            public function txt(string $host): array
            {
                return $this->answers['txt'][$host] ?? [];
            }

            public function mx(string $domain): array
            {
                return $this->answers['mx'][$domain] ?? [];
            }

            public function ptr(string $ip): ?string
            {
                return $this->answers['ptr'][$ip] ?? null;
            }
        });
    }

    /** @return array<string, string> check => status */
    private function statuses(): array
    {
        return collect(app(SendPreflight::class)->run())->pluck('status', 'check')->all();
    }

    private function healthy(): array
    {
        return [
            'a' => ['mail.example.test' => [self::IP], 'mx.send.example.test' => [self::IP]],
            'ptr' => [self::IP => 'mail.example.test'],
            'mx' => ['send.example.test' => ['mx.send.example.test']],
            'txt' => [
                'send.example.test' => ['v=spf1 mx ~all'],
                'dkim._domainkey.send.example.test' => ['v=DKIM1;k=rsa;p=MIIB'],
                '_dmarc.send.example.test' => ['v=DMARC1; p=none'],
            ],
        ];
    }

    public function test_everything_in_place_passes(): void
    {
        $this->dns($this->healthy());

        $results = app(SendPreflight::class)->run();

        $this->assertTrue(SendPreflight::passed($results), json_encode($results));
    }

    public function test_missing_ptr_fails_with_provider_hint(): void
    {
        $dns = $this->healthy();
        unset($dns['ptr']);
        $this->dns($dns);

        $results = collect(app(SendPreflight::class)->run())->keyBy('check');

        $this->assertSame('fail', $results['PTR '.self::IP]['status']);
        $this->assertStringContainsString('mail.example.test', $results['PTR '.self::IP]['detail']);
    }

    public function test_spf_that_does_not_cover_the_egress_ip_fails(): void
    {
        $dns = $this->healthy();
        $dns['txt']['send.example.test'] = ['v=spf1 ip4:198.51.100.1 -all'];
        $this->dns($dns);

        $this->assertSame('fail', $this->statuses()['SPF send.example.test']);
    }

    public function test_missing_dkim_dmarc_identity_and_mailboxes_fail(): void
    {
        $dns = $this->healthy();
        unset($dns['txt']['dkim._domainkey.send.example.test'], $dns['txt']['_dmarc.send.example.test']);
        $this->dns($dns);
        config(['outreach.send.identity' => ['name' => '', 'organization' => 'O', 'address' => '']]);

        $s = collect(app(SendPreflight::class)->run())->keyBy('check');

        $this->assertSame('fail', $s['DKIM dkim._domainkey.send.example.test']['status']);
        $this->assertSame('fail', $s['DMARC send.example.test']['status']);
        $this->assertSame('fail', $s['sender identity']['status']);
        $this->assertStringContainsString('OUTREACH_SENDER_NAME, OUTREACH_SENDER_ADDRESS', $s['sender identity']['detail']);

        Sender::query()->update(['active' => false]);
        $this->assertSame('fail', $this->statuses()['mailbox pool']);
    }

    public function test_command_exit_code_reflects_readiness(): void
    {
        $this->dns($this->healthy());
        $this->artisan('outreach:send-preflight')->assertSuccessful();

        $this->dns([]);
        $this->artisan('outreach:send-preflight')->assertFailed();
    }
}
