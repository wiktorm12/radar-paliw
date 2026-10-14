<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Collection sitemaps built from raw entry data.
 *
 * The Antlers `collection` loop augments every field of every entry (Bard, assets, relations),
 * which exhausted the 128 MB memory limit once the blog passed ~275 articles.
 */
class CollectionSitemapController extends Controller
{
    public function __invoke(string $collection): Response
    {
        $base = rtrim(Site::default()->absoluteUrl(), '/');

        $urls = Entry::query()
            ->where('collection', $collection)
            ->whereStatus('published')
            ->get()
            ->reject(fn ($entry) => $entry->get('noindex') || $entry->get('canonical_url') || ! $entry->url())
            ->map(function ($entry) use ($base) {
                $url = $entry->url() === '/' ? $base.'/' : $base.$entry->url();

                return [
                    'loc' => $url,
                    'lastmod' => $entry->lastModified()->setTimezone('Europe/Warsaw')->toAtomString(),
                    'images' => $this->images($entry, $base),
                ];
            });

        return response()
            ->view('sitemaps.collection-xml', ['urls' => $urls])
            ->header('Content-Type', 'text/xml; charset=UTF-8');
    }

    /** Featured image plus image sets from the raw Bard content, as absolute URLs. */
    private function images($entry, string $base): array
    {
        $paths = array_filter([$entry->get('featured_image')]);

        foreach ((array) $entry->get('content') as $node) {
            if (($node['type'] ?? null) === 'set' && ($node['attrs']['values']['type'] ?? null) === 'image') {
                $paths[] = $node['attrs']['values']['image'] ?? null;
            }
        }

        return array_values(array_unique(array_map(
            fn ($path) => $base.'/img/'.ltrim($path, '/'),
            array_filter($paths, 'is_string'),
        )));
    }
}
