<?php

namespace App\Http\Middleware;

use App\Services\CashierShiftService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCashierShift
{
    public function __construct(
        private readonly CashierShiftService $cashierShiftService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->cashierShiftService->getActiveShiftForUser($user->id)) {
            $message = 'Shift kasir belum dibuka.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                ], 422);
            }

            // A pinned add-on (#251) survives: posted by the request, or else in the URL of the POS page it came from.
            parse_str((string) parse_url((string) $request->headers->get('referer'), PHP_URL_QUERY), $from);

            return to_route('transactions.index', $request->only('add_on_to') + Arr::only($from, 'add_on_to'))->with('error', $message);
        }

        return $next($request);
    }
}
