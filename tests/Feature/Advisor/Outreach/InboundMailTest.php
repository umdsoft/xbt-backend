<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Reply;
use App\Domains\Advisor\Outreach\Models\Suppression;
use App\Domains\Advisor\Outreach\Services\Inbox\InboundMail;
use App\Domains\Advisor\Outreach\Services\Inbox\InboundProcessor;
use App\Domains\Advisor\Outreach\Services\Sending\SendControl;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Support\Str;

/**
 * Incoming mail without AI: parsing (reply / auto-reply / unsubscribe /
 * bounce) from raw RFC 822 text, and the consequences on letters, contacts,
 * leads and the suppression list.
 */
class InboundMailTest extends OutreachTestCase
{
    /** A sent first letter with an approved follow-up waiting. @return array{0: Message, 1: Message} */
    private function sentSeries(): array
    {
        $company = $this->company($this->tuman(), ['tier' => 'A', 'sanctions_status' => 'clear', 'stage' => Stage::SENT]);
        $contact = $this->contact($company, ['email' => 'ceo@'.$company->domain]);
        $series = (string) Str::uuid();

        $first = $this->draft($contact, ['series_id' => $series]);
        $first->forceFill(['status' => Message::SENT, 'sent_at' => now(), 'message_id_header' => Str::uuid().'@invest.test'])->save();
        $second = $this->draft($contact, ['series_id' => $series, 'sequence_step' => 2]);
        $second->forceFill(['status' => Message::APPROVED, 'approved_by_user_id' => $this->viloyat()->id, 'approved_at' => now()])->save();

        return [$first->fresh(), $second->fresh()];
    }

    private function raw(string $from, string $subject, string $body, array $headers = []): string
    {
        $lines = array_merge([
            'From: Jane <'.$from.'>',
            'To: team@invest.test',
            'Subject: '.$subject,
            'Date: Wed, 07 Oct 2026 10:00:00 +0200',
            'Message-ID: <'.Str::uuid().'@client.test>',
            'Content-Type: text/plain; charset=utf-8',
        ], $headers);

        return implode("\r\n", $lines)."\r\n\r\n".$body."\r\n";
    }

    private function dsn(string $failed, string $status, string $originalId): string
    {
        $b = 'b'.Str::random(8);

        return implode("\r\n", [
            'From: Mail Delivery System <MAILER-DAEMON@mx.client.test>',
            'To: bounce@invest.test',
            'Subject: Undelivered Mail Returned to Sender',
            'Message-ID: <'.Str::uuid().'@mx.client.test>',
            'Content-Type: multipart/report; report-type=delivery-status; boundary="'.$b.'"',
            '', '--'.$b, 'Content-Type: text/plain', '', 'Delivery failed.',
            '--'.$b, 'Content-Type: message/delivery-status', '',
            'Reporting-MTA: dns; mx.client.test', '', 'Final-Recipient: rfc822; '.$failed, 'Action: failed', 'Status: '.$status,
            '--'.$b, 'Content-Type: text/rfc822-headers', '', 'Message-ID: <'.$originalId.'>', 'Subject: Delivery center in Khorezm',
            '--'.$b.'--', '',
        ]);
    }

    private function process(string $raw): ?Reply
    {
        return app(InboundProcessor::class)->process(InboundMail::parse($raw));
    }

    public function test_parser_sorts_mail_kinds(): void
    {
        $this->assertSame('reply', InboundMail::parse($this->raw('a@x.test', 'Re: Khorezm', "Sounds interesting, let's talk.\n\n> If you would rather not hear from us again: https://x/unsubscribe"))->kind);
        $this->assertSame('auto_reply', InboundMail::parse($this->raw('a@x.test', 'Automatic reply: Khorezm', 'I am out of office'))->kind);
        $this->assertSame('auto_reply', InboundMail::parse($this->raw('a@x.test', 'Re: Khorezm', 'Away', ['Auto-Submitted: auto-replied']))->kind);
        $this->assertSame('unsubscribe', InboundMail::parse($this->raw('a@x.test', 'Re: Khorezm', 'Please remove me from your list.'))->kind);
        $this->assertSame('unsubscribe', InboundMail::parse($this->raw('a@x.test', 'Re: Khorezm', 'Пожалуйста, не пишите нам больше.'))->kind);

        $bounce = InboundMail::parse($this->dsn('gone@x.test', '5.1.1', 'orig@invest.test'));
        $this->assertSame('bounce', $bounce->kind);
        $this->assertTrue($bounce->isHardBounce());
        $this->assertSame('gone@x.test', $bounce->bounceRecipient);
        $this->assertContains('orig@invest.test', $bounce->references);
    }

    public function test_reply_marks_letter_stops_series_and_moves_lead(): void
    {
        [$first, $second] = $this->sentSeries();

        $reply = $this->process($this->raw($first->contact->email, 'Re: '.$first->subject, 'Yes, send details.', ['In-Reply-To: <'.$first->message_id_header.'>']));

        $this->assertSame($first->id, $reply->message_id);
        $this->assertSame($first->contact->company_id, $reply->company_id);
        $this->assertNull($reply->classification, 'meaning is classified later by Claude or a person');
        $this->assertSame(Message::REPLIED, $first->fresh()->status);
        $this->assertSame(Message::CANCELLED, $second->fresh()->status);
        $this->assertSame(Stage::REPLIED, $first->contact->company->fresh()->stage);
        $this->assertTrue(AuditEntry::query()->where('action', 'inbound.received')->where('entity_id', $reply->id)->exists());
    }

    public function test_reply_from_known_address_without_thread_headers_is_still_matched(): void
    {
        [$first] = $this->sentSeries();

        $reply = $this->process($this->raw(strtoupper($first->contact->email), 'Your letter', 'We are interested.'));

        $this->assertSame($first->contact_id, $reply->contact_id);
        $this->assertNull($reply->message_id);
        $this->assertSame(Stage::REPLIED, $first->contact->company->fresh()->stage);
    }

    public function test_auto_reply_does_not_stop_the_series(): void
    {
        [$first, $second] = $this->sentSeries();

        $this->process($this->raw($first->contact->email, 'Out of office', 'Back on Monday', ['In-Reply-To: <'.$first->message_id_header.'>']));

        $this->assertSame(Message::SENT, $first->fresh()->status);
        $this->assertSame(Message::APPROVED, $second->fresh()->status);
    }

    public function test_unsubscribe_request_is_honoured_immediately(): void
    {
        [$first, $second] = $this->sentSeries();

        $this->process($this->raw($first->contact->email, 'Re: Khorezm', 'Unsubscribe', ['In-Reply-To: <'.$first->message_id_header.'>']));

        $this->assertNotNull($first->contact->fresh()->unsubscribed_at);
        $this->assertSame(Message::CANCELLED, $second->fresh()->status);
        $this->assertTrue(Suppression::query()->where('email', $first->contact->email)->where('reason', 'unsubscribed')->exists());
        $this->assertSame(Stage::CLOSED_UNSUBSCRIBED, $first->contact->company->fresh()->stage);
    }

    public function test_hard_bounce_invalidates_email_and_soft_bounce_does_not(): void
    {
        [$first, $second] = $this->sentSeries();

        $this->process($this->dsn($first->contact->email, '4.2.2', $first->message_id_header));
        $this->assertSame('verified', $first->contact->fresh()->email_status);
        $this->assertSame(Message::SENT, $first->fresh()->status);

        $this->process($this->dsn($first->contact->email, '5.1.1', $first->message_id_header));
        $this->assertSame(Message::BOUNCED, $first->fresh()->status);
        $this->assertSame('invalid', $first->contact->fresh()->email_status);
        $this->assertSame(Message::CANCELLED, $second->fresh()->status);
        $this->assertTrue(Suppression::query()->where('email', $first->contact->email)->where('reason', 'hard_bounce')->exists());
    }

    public function test_same_mail_imported_twice_is_stored_once(): void
    {
        [$first] = $this->sentSeries();
        $raw = $this->raw($first->contact->email, 'Re: x', 'Hello', ['In-Reply-To: <'.$first->message_id_header.'>']);

        $this->assertNotNull($this->process($raw));
        $this->assertNull($this->process($raw));
        $this->assertSame(1, Reply::query()->where('contact_id', $first->contact_id)->count());
    }

    public function test_high_bounce_rate_opens_the_breaker(): void
    {
        $letters = collect(range(1, 20))->map(fn () => $this->sentSeries()[0]);

        foreach ($letters->take(2) as $letter) {
            $this->process($this->dsn($letter->contact->email, '5.1.1', $letter->message_id_header));
        }

        $this->assertSame(SendControl::BREAKER, app(SendControl::class)->stopReason());
    }
}
