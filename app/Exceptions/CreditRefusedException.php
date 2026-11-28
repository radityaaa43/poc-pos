<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Pay later refused by the customer's credit rules (REQ-007 BR-1, BR-2): a business answer, not an error to report. */
class CreditRefusedException extends RuntimeException implements ShouldntReport
{
    public const LIMIT_EXCEEDED = 'credit_limit_exceeded';

    public const OVERDUE = 'credit_overdue';

    private function __construct(public readonly string $reason, public readonly int $remaining, public readonly int $total)
    {
        // Indonesian like the rest of the API, which sets no locale; the web re-reads it in the user's language.
        parent::__construct(self::describe($reason, $remaining, 'id'));
    }

    public static function limitExceeded(int $remaining, int $total): self
    {
        return new self(self::LIMIT_EXCEEDED, $remaining, $total);
    }

    public static function overdue(int $remaining, int $total): self
    {
        return new self(self::OVERDUE, $remaining, $total);
    }

    /** The refusal in words, from lang/: in the request's language unless one is named. */
    public static function describe(string $reason, int $remaining, ?string $locale = null): string
    {
        return __("messages.credit.$reason", ['remaining' => 'Rp '.number_format($remaining, 0, ',', '.')], $locale);
    }

    /** Not a ValidationException: the API contract is a code and the remaining limit, not a list of field errors. */
    public function render(Request $request): Response
    {
        return $request->is('api/*')
            ? ApiResponse::error($this->getMessage(), 422, ['code' => $this->reason, 'remaining_limit' => $this->remaining])
            : back()->withErrors(['credit' => self::describe($this->reason, $this->remaining)]);
    }
}
