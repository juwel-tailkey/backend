<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureSessionDomain
{
    /**
     * Ensure the session cookie can actually be stored by the requesting host.
     *
     * When SESSION_DOMAIN is pinned to a specific domain (e.g. "localhost")
     * but the request arrives on a different host (e.g. a bare IP like
     * 34.150.126.247), the browser rejects the session cookie and the session
     * never sticks. In that case we fall back to a host-only cookie by nulling
     * the configured domain for this request, before StartSession runs.
     *
     * This must run before Sanctum's EnsureFrontendRequestsAreStateful
     * (which injects StartSession) so the adjusted config is in effect when
     * the session cookie is created.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('session.domain');

        if (is_string($configured) && $configured !== '') {
            $domain = ltrim($configured, '.');
            $host = $request->getHost();

            $matches = $host === $domain || str_ends_with($host, '.'.$domain);

            if (! $matches) {
                // Host-only cookie: the browser scopes it to the current host.
                config(['session.domain' => null]);
            }
        }

        return $next($request);
    }
}
