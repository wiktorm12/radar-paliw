<?php

namespace App\Http\Controllers;

use App\Support\Lastmod;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Statamic\Facades\Site;

class SitemapIndexController extends Controller
{
    public function __invoke(): Response
    {
        $base = rtrim(Site::default()->absoluteUrl(), '/');

        $collections = collect(['pages', 'promotions', 'stations', 'articles', 'authors'])
            ->mapWithKeys(fn ($collection) => [
                $collection => CollectionSitemapController::indexable($collection)
                    ->map(fn ($entry) => Lastmod::of($entry))
                    ->max(),
            ]);

        $news = NewsSitemapController::entries()->map(fn ($entry) => Lastmod::of($entry))->max();

        $sitemaps = collect([
            'pages' => $collections['pages'],
            'promotions' => $collections['promotions'],
            'stations' => $collections['stations'],
            'articles' => $collections['articles'],
            'paliwa' => $collections['promotions'],
            'kategorie' => $collections['articles'],
            'authors' => $collections['authors'],
            'cpn' => Lastmod::cpn(),
            'news' => $news,
        ])->map(fn ($lastmod, $name) => [
            'loc' => "{$base}/sitemap-{$name}.xml",
            'lastmod' => $lastmod ? Carbon::instance($lastmod)->setTimezone(Lastmod::TIMEZONE)->toAtomString() : null,
        ])->values();

        return response()
            ->view('sitemaps.index', ['sitemaps' => $sitemaps])
            ->header('Content-Type', 'text/xml; charset=UTF-8');
    }
}
