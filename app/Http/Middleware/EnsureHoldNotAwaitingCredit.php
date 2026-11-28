<?php

namespace App\Http\Middleware;

use App\Models\CreditApprovalRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A held basket waiting for a credit approver cannot be resumed or deleted (web and API hold routes). */
class EnsureHoldNotAwaitingCredit
{
    public function handle(Request $request, Closure $next): Response
    {
        CreditApprovalRequest::assertHoldIsFree($request->route('holdId'));

        return $next($request);
    }
}
