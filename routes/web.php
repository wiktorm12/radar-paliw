<?php

use Illuminate\Support\Facades\Route;
use Statamic\Facades\GlobalSet;

Route::statamic('sitemap.xml', 'sitemaps/index', ['layout' => null, 'content_type' => 'xml']);

foreach (['pages', 'promotions', 'stations', 'articles', 'authors'] as $collection) {
    Route::statamic("sitemap-{$collection}.xml", 'sitemaps/collection', [
        'layout' => null,
        'content_type' => 'xml',
        'sitemap_collection' => $collection,
    ]);
}

foreach (['paliwa', 'kategorie'] as $taxonomy) {
    Route::statamic("sitemap-{$taxonomy}.xml", 'sitemaps/taxonomy', [
        'layout' => null,
        'content_type' => 'xml',
        'sitemap_taxonomy' => $taxonomy,
    ]);
}

Route::statamic('blog/feed.xml', 'feeds/articles', ['layout' => null, 'content_type' => 'rss']);
Route::statamic('promocje/feed.xml', 'feeds/promotions', ['layout' => null, 'content_type' => 'rss']);

Route::permanentRedirect('promotions/paliwa/{slug}', '/paliwa/{slug}');
Route::permanentRedirect('articles/kategorie/{slug}', '/kategorie/{slug}');

Route::get('robots.txt', function () {
    $lines = app()->environment('production')
        ? ['User-agent: *', 'Disallow: /cp', 'Disallow: /*?*utm_', '', 'Sitemap: '.url('sitemap.xml')]
        : ['User-agent: *', 'Disallow: /'];

    return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});

Route::get('ads.txt', function () {
    $content = GlobalSet::findByHandle('ads')?->inDefaultSite()?->get('ads_txt') ?? '';

    return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});
