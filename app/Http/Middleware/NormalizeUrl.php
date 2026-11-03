<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301-redirects duplicate URL variants (trailing slash, double slashes, upper-case paths,
 * invalid ?page=) to their canonical form.
 */
class NormalizeUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $path = $request->getPathInfo();
        $cp = '/'.trim(config('statamic.cp.route', 'cp'), '/');

        if ($path === $cp || str_starts_with($path, $cp.'/') || str_starts_with($path, '/!/')) {
            return $next($request);
        }

        $normalized = preg_replace('#/{2,}#', '/', $path);
        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }
        if ($normalized === '') {
            $normalized = '/';
        }

        if (! $this->isStaticAssetPath($normalized)) {
            $normalized = preg_replace_callback('/%[0-9A-Fa-f]{2}(*SKIP)(*FAIL)|[A-Z]+/', fn ($m) => strtolower($m[0]), $normalized);
        }

        $query = $request->server->get('QUERY_STRING', '');
        $changed = $normalized !== $path;

        if ($request->query->has('page')) {
            $page = $request->query->all()['page'];

            if (! is_string($page) || ! ctype_digit($page) || (int) $page <= 1) {
                $params = $request->query->all();
                unset($params['page']);
                $query = http_build_query($params);
                $changed = true;
            }
        }

        if (! $changed) {
            return $next($request);
        }

        $target = $request->getSchemeAndHttpHost().$normalized.($query !== '' ? '?'.$query : '');

        return redirect($target, 301);
    }

    private function isStaticAssetPath(string $path): bool
    {
        foreach (['/img/', '/build/', '/og/', '/vendor/', '/storage/'] as $prefix) {
            if (str_starts_with($path.'/', $prefix)) {
                return true;
            }
        }

        return (bool) preg_match('/\.[A-Za-z0-9]{1,5}$/', basename($path));
    }
}
