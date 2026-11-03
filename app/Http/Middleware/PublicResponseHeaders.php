<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registered as global middleware (wraps session/CSRF and CP routes) so it sees the response after
 * session/CSRF cookies were attached.
 */
class PublicResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $path = $request->getPathInfo();
        $cp = '/'.trim(config('statamic.cp.route', 'cp'), '/');
        $isCp = $path === $cp || str_starts_with($path, $cp.'/');
        $isInternal = str_starts_with($path, '/!/');

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if ($isCp) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type');

        if (str_starts_with($contentType, 'text/html')) {
            $response->headers->set('Content-Language', 'pl');
        }

        if ($isInternal || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $response;
        }

        if ($response->getStatusCode() !== 200
            || $request->has('live-preview')
            || $request->has('token')
            || Auth::check()) {
            return $response;
        }

        $session = config('session.cookie');
        foreach ($response->headers->getCookies() as $cookie) {
            if (in_array($cookie->getName(), [$session, 'XSRF-TOKEN'], true)) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        $response->headers->remove('Pragma');
        $response->headers->remove('Expires');

        if (str_starts_with($path, '/og/')) {
            return $response;
        }

        $sharedMaxAge = $path === '/program-cpn' || str_starts_with($path, '/program-cpn/') ? 600 : 3600;
        $response->headers->set('Cache-Control', "public, max-age=300, s-maxage={$sharedMaxAge}, stale-while-revalidate=86400");

        if (str_starts_with($contentType, 'text/html')
            && ! $response instanceof StreamedResponse
            && ! $response instanceof BinaryFileResponse) {
            $response->setEtag(md5((string) $response->getContent()));
            $response->isNotModified($request);
        }

        return $response;
    }
}
