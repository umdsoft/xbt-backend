<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Support;

/**
 * Lead stages and the allowed transitions between them (PLAN-crm.md §3).
 *
 * This class is the single source of truth: controllers and MCP tools never
 * change `companies.stage` directly, they go through StageMachine, which asks
 * this class whether a move is allowed for the given channel.
 */
final class Stage
{
    public const FOUND = 'found';

    public const VERIFIED = 'verified';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const SENT = 'sent';

    public const REPLIED = 'replied';

    public const MEETING_BOOKED = 'meeting_booked';

    public const MEETING_DONE = 'meeting_done';

    public const VISIT_OR_MOU = 'visit_or_mou';

    public const RESIDENT_OR_OFFICE = 'resident_or_office';

    public const CLOSED_DECLINED = 'closed_declined';

    public const CLOSED_UNSUBSCRIBED = 'closed_unsubscribed';

    public const BLOCKED_SANCTIONS = 'blocked_sanctions';

    /** Channels that may request a transition. */
    public const VIA_UI = 'ui';

    public const VIA_MCP = 'mcp';

    public const VIA_SYSTEM = 'system';

    /** Open pipeline stages, in order. */
    public const OPEN = [
        self::FOUND, self::VERIFIED, self::AWAITING_APPROVAL, self::SENT, self::REPLIED,
        self::MEETING_BOOKED, self::MEETING_DONE, self::VISIT_OR_MOU, self::RESIDENT_OR_OFFICE,
    ];

    public const CLOSED = [self::CLOSED_DECLINED, self::CLOSED_UNSUBSCRIBED, self::BLOCKED_SANCTIONS];

    /**
     * Forward/backward moves inside the pipeline: from => [to => channels].
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const PIPELINE = [
        self::FOUND => [
            self::VERIFIED => [self::VIA_UI, self::VIA_MCP],
        ],
        self::VERIFIED => [
            self::AWAITING_APPROVAL => [self::VIA_UI, self::VIA_MCP],
        ],
        self::AWAITING_APPROVAL => [
            self::VERIFIED => [self::VIA_UI, self::VIA_MCP],   // drafts rejected, back to research
            self::SENT => [self::VIA_SYSTEM],                  // only send_approved (stage 3)
        ],
        self::SENT => [
            self::REPLIED => [self::VIA_UI, self::VIA_MCP, self::VIA_SYSTEM],
        ],
        self::REPLIED => [
            self::MEETING_BOOKED => [self::VIA_UI, self::VIA_MCP, self::VIA_SYSTEM],
        ],
        self::MEETING_BOOKED => [
            self::MEETING_DONE => [self::VIA_UI],              // human decision (SPEC §5)
            self::REPLIED => [self::VIA_UI, self::VIA_MCP],    // meeting postponed
        ],
        self::MEETING_DONE => [
            self::VISIT_OR_MOU => [self::VIA_UI],              // human decision (SPEC §5)
        ],
        self::VISIT_OR_MOU => [
            self::RESIDENT_OR_OFFICE => [self::VIA_UI],        // human decision
        ],
    ];

    /** Stages from which a lead may be closed as declined. */
    private const DECLINABLE = [
        self::SENT, self::REPLIED, self::MEETING_BOOKED, self::MEETING_DONE, self::VISIT_OR_MOU,
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return [...self::OPEN, ...self::CLOSED];
    }

    public static function isClosed(string $stage): bool
    {
        return in_array($stage, self::CLOSED, true);
    }

    /**
     * Can `$from` move to `$to` through `$via`?
     *
     * Reopening a declined lead (closed_declined -> verified) is allowed only
     * through the UI; the caller must additionally require the viloyat role.
     */
    public static function allows(string $from, string $to, string $via): bool
    {
        if ($from === $to) {
            return false;
        }

        if ($to === self::BLOCKED_SANCTIONS) {
            // Sanctions hit blocks the lead from anywhere, by any channel.
            return $from !== self::BLOCKED_SANCTIONS;
        }

        if ($to === self::CLOSED_UNSUBSCRIBED) {
            // Unsubscribe is honoured immediately from any open stage.
            return ! self::isClosed($from);
        }

        if ($to === self::CLOSED_DECLINED) {
            return in_array($from, self::DECLINABLE, true);
        }

        if ($from === self::CLOSED_DECLINED && $to === self::VERIFIED) {
            return $via === self::VIA_UI;
        }

        return in_array($via, self::PIPELINE[$from][$to] ?? [], true);
    }

    /**
     * Stages reachable from `$from` through `$via` (for UI buttons / MCP hints).
     *
     * @return array<int, string>
     */
    public static function nextFor(string $from, string $via): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (string $to): bool => self::allows($from, $to, $via),
        ));
    }
}
