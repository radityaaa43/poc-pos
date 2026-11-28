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

    private function __construct(string $message, public readonly string $reason, public readonly int $remaining)
    {
        parent::__construct($message);
    }

    public static function limitExceeded(int $remaining): self
    {
        return new self('Melebihi batas piutang — sisa Rp '.number_format($remaining, 0, ',', '.'), self::LIMIT_EXCEEDED, $remaining);
    }

    public static function overdue(int $remaining): self
    {
        return new self('Lunasi piutang yang lewat jatuh tempo dulu', self::OVERDUE, $remaining);
    }

    /** Not a ValidationException: the API contract is a code and the remaining limit, not a list of field errors. */
    public function render(Request $request): Response
    {
        return $request->is('api/*')
            ? ApiResponse::error($this->getMessage(), 422, ['code' => $this->reason, 'remaining_limit' => $this->remaining])
            : back()->withErrors(['credit' => $this->getMessage()]);
    }
}
