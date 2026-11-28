<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For requests a page sends on its own (polls, background JSON), whose response never shows flash data.
 * Laravel ages the flash on every request: without this, one that lands between a form post and its
 * redirected page deletes the errors or success meant for that page (#473).
 */
class KeepFlashForPolls
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->session()->reflash();

        return $next($request);
    }
}
