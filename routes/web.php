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

Route::get('llms.txt', function () {
    $body = (new \Statamic\View\View)->template('llms/index')->layout(null)->render();
    $body = preg_replace(['/\n{2,}(?=- )/', '/\n{3,}/', '/^(## .+)\n(?=\S)/m'], ["\n", "\n\n", "$1\n\n"], trim($body))."\n";

    return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});

Route::get('robots.txt', function () {
    if (! app()->environment('production')) {
        $lines = ['User-agent: *', 'Disallow: /'];
    } else {
        $aiBots = [
            'OAI-SearchBot', 'ChatGPT-User', 'GPTBot', 'PerplexityBot', 'Perplexity-User',
            'ClaudeBot', 'Claude-SearchBot', 'Claude-User', 'Google-Extended', 'Applebot-Extended',
        ];

        $lines = ['User-agent: *', 'Disallow: /cp', 'Disallow: /*?*utm_', ''];

        foreach ($aiBots as $bot) {
            array_push($lines, "User-agent: {$bot}", 'Allow: /', 'Disallow: /cp', '');
        }

        $lines[] = 'Sitemap: '.rtrim(config('app.url'), '/').'/sitemap.xml';
    }

    return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});

Route::get('ads.txt', function () {
    $content = GlobalSet::findByHandle('ads')?->inDefaultSite()?->get('ads_txt') ?? '';

    return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});
