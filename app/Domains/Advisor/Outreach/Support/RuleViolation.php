<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Support;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A business rule refused the operation. `reason` is a stable machine code
 * (English) for MCP clients and tests; the message is user-facing (Uzbek,
 * Cyrillic, like the rest of the advisor UI).
 *
 * HTTP mapping: forbidden -> 403, not_found -> 404, everything else -> 422.
 */
final class RuleViolation extends RuntimeException
{
    public const FORBIDDEN = 'forbidden';

    public const NOT_FOUND = 'not_found';

    public const INVALID_DOMAIN = 'invalid_domain';

    public const COUNTRY_UNKNOWN = 'country_unknown';

    public const COUNTRY_EXCLUDED = 'country_excluded';

    public const DUPLICATE = 'duplicate';

    public const CONTACT_LIMIT = 'contact_limit';

    public const TRANSITION_NOT_ALLOWED = 'transition_not_allowed';

    public const TRANSITION_PRECONDITION = 'transition_precondition';

    public const IRREVERSIBLE = 'irreversible';

    public const NOT_APPROVABLE = 'not_approvable';

    public const INVALID_INPUT = 'invalid_input';

    /** A letter series cannot be drafted for this contact/lead (context.check says why). */
    public const NOT_DRAFTABLE = 'not_draftable';

    /** Only one open series per contact / one open meeting per lead (context carries its id). */
    public const ALREADY_OPEN = 'already_open';

    /** The record exists but is not editable in its current status. */
    public const NOT_EDITABLE = 'not_editable';

    /** @param  array<string, mixed>  $context */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /** Laravel calls this when the exception escapes a controller. */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'reason' => $this->reason,
            'context' => (object) $this->context,
        ], $this->httpStatus());
    }

    public function httpStatus(): int
    {
        return match ($this->reason) {
            self::FORBIDDEN => 403,
            self::NOT_FOUND => 404,
            default => 422,
        };
    }
}
