<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Sender;
use App\Domains\Advisor\Outreach\Models\SendLogEntry;
use App\Domains\Advisor\Outreach\Models\Suppression;
use App\Domains\Advisor\Outreach\Services\Sending\SendControl;
use App\Domains\Advisor\Outreach\Services\Sending\SendDispatcher;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/**
 * The scheduler tick end to end with an in-memory SMTP transport: what goes
 * out, what is held, caps, windows, idempotency, failures and the breaker.
 */
class SendDispatchTest extends OutreachTestCase
{
    /** Wednesday 10:00 in Berlin — inside the default business window. */
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-07 08:00:00', 'UTC');
        Carbon::setTestNow($this->now);
        Cache::flush();

        // The dev DB may hold real/demo mailboxes; tests use only their own
        // (rolled back with the transaction).
        Sender::query()->update(['active' => false]);
        // Likewise park letters already queued in the dev DB (e.g. DEMO series).
        Message::query()->whereIn('status', [Message::APPROVED, Message::SENDING])->update(['status' => Message::DRAFT]);

        config([
            'mail.mailers.outreach' => ['transport' => 'array'],
            'outreach.send.mode' => 'live',
            'outreach.send.identity' => ['name' => 'Test Sender', 'title' => 'Advisor', 'organization' => 'Khorezm Regional Government', 'address' => 'Urgench'],
            'outreach.send.warmup' => [10, 20, 30],
            'outreach.send.batch' => 10,
            'outreach.send.spacing_min' => 0,
            'outreach.send.spacing_max' => 0,
        ]);
        $this->country('XA')->forceFill(['timezone' => 'Europe/Berlin'])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sender(array $attrs = []): Sender
    {
        return Sender::query()->create($attrs + [
            'email' => 'team'.Str::random(5).'@invest.test',
            'mailer' => 'outreach',
            'display_name' => 'Test Sender',
            'warmup_started_on' => '2026-09-01',
            'daily_cap_max' => 40,
        ]);
    }

    private function approved(array $company = [], array $message = []): Message
    {
        $c = $this->company($this->tuman(), $company + ['tier' => 'A', 'sanctions_status' => 'clear', 'stage' => Stage::AWAITING_APPROVAL]);
        $draft = $this->draft($this->contact($c), $message + ['series_id' => (string) Str::uuid()]);
        $draft->forceFill(['status' => Message::APPROVED, 'approved_by_user_id' => $this->viloyat()->id, 'approved_at' => now()])->save();

        return $draft->fresh();
    }

    private function tick(?Carbon $at = null): array
    {
        return app(SendDispatcher::class)->run($at ?? $this->now);
    }

    /** @return array<int, Email> */
    private function outbox(): array
    {
        return app('mail.manager')->mailer('outreach')->getSymfonyTransport()->messages()
            ->map(fn (SentMessage $m) => $m->getOriginalMessage())->all();
    }

    private function failWith(\Throwable $e): void
    {
        Mail::extend('failing', fn () => new class($e) extends AbstractTransport
        {
            public function __construct(private \Throwable $e)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                throw $this->e;
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.outreach' => ['transport' => 'failing']]);
        app('mail.manager')->purge('outreach');
    }

    public function test_sends_an_approved_letter_with_required_headers_and_footer(): void
    {
        $sender = $this->sender();
        $m = $this->approved();

        $this->assertSame(['sent' => 1], $this->tick());

        $email = $this->outbox()[0];
        $this->assertSame($m->contact->email, $email->getTo()[0]->getAddress());
        $this->assertSame($sender->email, $email->getFrom()[0]->getAddress());
        $headers = $email->getHeaders();
        $this->assertStringContainsString('/api/outreach/unsubscribe/'.$m->contact_id, $headers->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertStringContainsString('Khorezm Regional Government', $email->getTextBody());
        $this->assertStringContainsString('unsubscribe', $email->getTextBody());

        $fresh = $m->fresh();
        $this->assertSame(Message::SENT, $fresh->status);
        $this->assertSame($sender->id, $fresh->sender_id);
        $this->assertNotNull($fresh->smtp_message_id);
        $this->assertSame(Stage::SENT, $fresh->contact->company->stage);
        $this->assertTrue(AuditEntry::query()->where('entity_id', $m->id)->where('action', 'message.sent')->where('actor', 'system')->exists());
    }

    public function test_off_mode_and_missing_identity_send_nothing(): void
    {
        $this->sender();
        $this->approved();

        config(['outreach.send.mode' => 'off']);
        $this->assertSame([], $this->tick());

        config(['outreach.send.mode' => 'live', 'outreach.send.identity' => ['name' => '', 'organization' => '', 'address' => '']]);
        $this->assertSame([], $this->tick());
        $this->assertSame([], $this->outbox());
    }

    public function test_test_mode_redirects_every_letter(): void
    {
        config(['outreach.send.mode' => 'test', 'outreach.send.test_recipient' => 'qa@example.test']);
        $this->sender();
        $m = $this->approved();

        $this->tick();

        $email = $this->outbox()[0];
        $this->assertSame('qa@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame($m->contact->email, $email->getHeaders()->get('X-Outreach-Original-To')->getBodyAsString());
    }

    public function test_nothing_goes_out_of_business_hours_or_at_weekends(): void
    {
        $this->sender();
        $m = $this->approved();

        $this->tick(Carbon::parse('2026-10-07 18:00:00', 'UTC'));       // 20:00 Berlin
        $this->tick(Carbon::parse('2026-10-10 09:00:00', 'UTC'));       // Saturday
        $this->assertSame(Message::APPROVED, $m->fresh()->status);
        $this->assertSame([], $this->outbox());
    }

    public function test_guard_refusals_are_applied_and_logged(): void
    {
        $this->sender();
        $suppressed = $this->approved();
        Suppression::query()->create(['email' => strtolower($suppressed->contact->email), 'reason' => 'unsubscribed']);
        $unchecked = $this->approved(['sanctions_status' => 'unchecked']);

        $this->tick();
        $this->tick();

        $this->assertSame(Message::CANCELLED, $suppressed->fresh()->status);
        $this->assertSame(Message::APPROVED, $unchecked->fresh()->status);
        $this->assertSame(1, SendLogEntry::query()->where('message_id', $unchecked->id)->where('outcome', 'held')->count(), 'hold logged once');
        $this->assertSame([], $this->outbox());
    }

    public function test_mailbox_warmup_cap_and_domain_cap(): void
    {
        $this->sender(['warmup_started_on' => '2026-10-07']);           // first week: 10/day
        foreach (range(1, 12) as $_) {
            $this->approved();
        }

        $this->tick();
        $this->tick();

        $this->assertCount(10, $this->outbox());

        Cache::flush();
        $this->sender();
        $sameDomain = collect(range(1, 3))->map(fn () => $this->approved())
            ->each(fn (Message $m) => $m->contact->forceFill(['email' => Str::random(6).'@bigcorp.test'])->save());
        $this->tick();

        $this->assertSame(2, $sameDomain->filter(fn (Message $m) => $m->fresh()->status === Message::SENT)->count());
    }

    public function test_a_letter_is_claimed_once_even_if_two_runs_overlap(): void
    {
        $sender = $this->sender();
        $m = $this->approved();

        // Another worker already claimed it.
        Message::query()->whereKey($m->id)->update(['status' => Message::SENDING, 'claim_id' => (string) Str::uuid(), 'sender_id' => $sender->id]);

        $this->tick();

        $this->assertSame([], $this->outbox());
        $this->assertSame(Message::SENDING, $m->fresh()->status);
    }

    public function test_follow_ups_are_scheduled_from_the_real_send_time(): void
    {
        $this->sender();
        $first = $this->approved();
        $second = $this->draft($first->contact, ['series_id' => $first->series_id, 'sequence_step' => 2]);
        $second->forceFill(['status' => Message::APPROVED, 'approved_by_user_id' => $first->approved_by_user_id, 'approved_at' => now()])->save();

        $this->tick();

        $this->assertSame(Message::SENT, $first->fresh()->status);
        $this->assertSame(Message::APPROVED, $second->fresh()->status);
        $this->assertTrue($second->fresh()->scheduled_for->equalTo($this->now->copy()->addDays(4)));

        Cache::flush();
        $this->tick(Carbon::parse('2026-10-12 08:00:00', 'UTC'));        // day 5, Monday
        $followUp = $this->outbox()[1];
        $this->assertSame(Message::SENT, $second->fresh()->status);
        $this->assertStringContainsString($first->fresh()->message_id_header, $followUp->getHeaders()->get('In-Reply-To')->getBodyAsString());
    }

    public function test_temporary_failure_is_retried_later_and_permanent_failure_stops(): void
    {
        $this->sender();
        $m = $this->approved();
        $this->failWith(new UnexpectedResponseException('451 try again later', 451));

        $this->assertSame(['deferred' => 1], $this->tick());
        $fresh = $m->fresh();
        $this->assertSame(Message::APPROVED, $fresh->status);
        $this->assertSame(1, $fresh->send_attempts);
        $this->assertTrue($fresh->scheduled_for->greaterThan($this->now));

        $this->failWith(new UnexpectedResponseException('550 mailbox unavailable', 550));
        Cache::flush();
        $this->tick($this->now->copy()->addHour());
        $this->assertSame(Message::FAILED, $m->fresh()->status);
    }

    public function test_ambiguous_failure_is_never_resent_automatically(): void
    {
        $this->sender();
        $m = $this->approved();
        $this->failWith(new TransportException('Connection to "mail" has been closed unexpectedly.'));

        $this->tick();
        Cache::flush();
        $this->tick($this->now->copy()->addMinutes(10));

        $this->assertSame(Message::SEND_UNKNOWN, $m->fresh()->status);
        $this->assertSame(1, SendLogEntry::query()->where('message_id', $m->id)->where('outcome', 'unknown')->count());
    }

    public function test_block_response_pauses_the_mailbox(): void
    {
        $sender = $this->sender();
        $this->approved();
        $this->failWith(new UnexpectedResponseException('554 5.7.1 Service unavailable; client host blocked using Spamhaus', 554));

        $this->tick();

        $this->assertNotNull($sender->fresh()->paused_at);
    }

    public function test_consecutive_failures_open_the_breaker(): void
    {
        config(['outreach.send.breaker_consecutive_failures' => 3]);
        $this->sender();
        foreach (range(1, 4) as $_) {
            $this->approved();
        }
        $this->failWith(new UnexpectedResponseException('550 no such user', 550));

        $this->tick();

        $this->assertSame(SendControl::BREAKER, app(SendControl::class)->stopReason());
        $this->assertSame(3, Message::query()->where('status', Message::FAILED)->count(), 'stops right after the breaker opens');
    }

    public function test_unsubscribe_link_one_click_and_confirmation_page(): void
    {
        $m = $this->approved();
        $url = URL::signedRoute('api.outreach.unsubscribe', ['contact' => $m->contact_id]);

        $this->get($url)->assertOk()->assertSee('Unsubscribe');
        $this->assertNull($m->contact->fresh()->unsubscribed_at, 'GET must not unsubscribe (link scanners)');

        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('You have been unsubscribed');

        $this->assertNotNull($m->contact->fresh()->unsubscribed_at);
        $this->assertSame(Message::CANCELLED, $m->fresh()->status);
        $this->assertTrue(Suppression::query()->where('email', strtolower($m->contact->email))->exists());
        $this->assertSame(Stage::CLOSED_UNSUBSCRIBED, $m->contact->company->fresh()->stage);
    }

    public function test_unsigned_or_tampered_unsubscribe_link_is_refused(): void
    {
        $m = $this->approved();
        $other = $this->approved();
        $url = URL::signedRoute('api.outreach.unsubscribe', ['contact' => $m->contact_id]);

        $this->post('/api/outreach/unsubscribe/'.$m->contact_id)->assertForbidden();
        $this->post(str_replace($m->contact_id, $other->contact_id, $url))->assertForbidden();
        $this->assertNull($other->contact->fresh()->unsubscribed_at);
    }
}
