<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectDebugHttpToHttps
{
    /**
     * Redirect browser traffic hitting the plain-HTTP loopback listener
     * over to the public HTTPS listener.
     *
     * Behind RoadRunner's TLS termination PHP always sees plain HTTP, so
     * this gates on the listener PORT, never on the scheme (scheme-gating
     * would redirect-loop the HTTPS listener too). Only cacheable methods
     * are redirected; API posts and the /up health check pass through.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethodCacheable()
            && ! $request->is('up')
            && (int) $request->getPort() === (int) config('app.http_debug_port', 18085)
        ) {
            $target = 'https://'.$request->getHost().':'.config('app.https_port', 8085).$request->getRequestUri();

            return redirect()->to($target, 302);
        }

        return $next($request);
    }
}
