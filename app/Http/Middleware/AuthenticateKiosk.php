<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateKiosk
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('kiosk.token');

        abort_unless($expected !== '' && hash_equals($expected, (string) $request->header('X-Kiosk-Token')), 401, 'Invalid kiosk token.');

        return $next($request);
    }
}
