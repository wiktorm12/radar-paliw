<?php

namespace App\Http\Controllers;

use App\Support\Lastmod;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class NewsSitemapController extends Controller
{
    private const WINDOW_HOURS = 48;

    private const LIMIT = 1000;

    public function __invoke(): Response
    {
        $base = rtrim(Site::default()->absoluteUrl(), '/');

        $urls = self::entries()->map(fn ($entry) => [
            'loc' => $base.$entry->url(),
            'published' => $entry->hasTime()
                ? Lastmod::warsaw($entry->date())->toAtomString()
                : $entry->date()->format('Y-m-d'),
            'title' => trim(strip_tags((string) $entry->get('title'))),
        ]);

        return response()
            ->view('sitemaps.news', ['urls' => $urls])
            ->header('Content-Type', 'text/xml; charset=UTF-8');
    }

    /** Articles from the last 48 hours, newest first; an article dated without a time counts for its whole day. */
    public static function entries(): Collection
    {
        $now = Carbon::now();
        $cutoff = $now->copy()->subHours(self::WINDOW_HOURS);

        return CollectionSitemapController::indexable('articles')
            ->filter(function ($entry) use ($now, $cutoff) {
                if (! $entry->hasDate()) {
                    return false;
                }

                $date = Lastmod::entryDate($entry);

                return $date->lte($now)
                    && ($entry->hasTime() ? $date->gte($cutoff) : $date->gte($cutoff->copy()->setTimezone(Lastmod::TIMEZONE)->startOfDay()));
            })
            ->sortByDesc(fn ($entry) => $entry->date())
            ->take(self::LIMIT)
            ->values();
    }
}
