<?php

namespace App\Http\Controllers;

use App\Support\Lastmod;
use Illuminate\Http\Response;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

class TaxonomySitemapController extends Controller
{
    private const COLLECTIONS = ['paliwa' => 'promotions', 'kategorie' => 'articles'];

    public function __invoke(string $taxonomy): Response
    {
        $base = rtrim(Site::default()->absoluteUrl(), '/');

        $terms = Term::query()
            ->where('taxonomy', $taxonomy)
            ->get()
            ->reject(fn ($term) => $term->get('noindex') || $term->get('canonical_url'))
            ->map(function ($term) use ($base) {
                $lastmod = $term->queryEntries()
                    ->whereStatus('published')
                    ->get()
                    ->map(fn ($entry) => Lastmod::of($entry))
                    ->max();

                return $lastmod ? ['loc' => $base.$term->url(), 'lastmod' => $lastmod] : null;
            })
            ->filter()
            ->sortBy('loc')
            ->values();

        $archive = CollectionSitemapController::indexable(self::COLLECTIONS[$taxonomy])
            ->map(fn ($entry) => Lastmod::of($entry))
            ->max();

        $urls = collect([['loc' => "{$base}/{$taxonomy}", 'lastmod' => $archive]])
            ->merge($terms)
            ->map(fn ($url) => [
                'loc' => $url['loc'],
                'lastmod' => $url['lastmod']?->copy()->setTimezone(Lastmod::TIMEZONE)->toAtomString(),
            ]);

        return response()
            ->view('sitemaps.taxonomy', ['urls' => $urls])
            ->header('Content-Type', 'text/xml; charset=UTF-8');
    }
}
