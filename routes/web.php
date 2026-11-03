<?php

use App\Http\Controllers\CollectionSitemapController;
use App\Http\Controllers\CpnController;
use App\Http\Controllers\NewsSitemapController;
use App\Http\Controllers\OgImageController;
use App\Http\Controllers\SitemapIndexController;
use App\Http\Controllers\TaxonomySitemapController;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\GlobalSet;

Route::get('sitemap.xml', SitemapIndexController::class);

Route::get('sitemap-news.xml', NewsSitemapController::class);

Route::get('sitemap-{collection}.xml', CollectionSitemapController::class)
    ->whereIn('collection', ['pages', 'promotions', 'stations', 'articles', 'authors']);

Route::get('sitemap-{taxonomy}.xml', TaxonomySitemapController::class)
    ->whereIn('taxonomy', ['paliwa', 'kategorie']);

Route::statamic('blog/feed.xml', 'feeds/articles', ['layout' => null, 'content_type' => 'rss']);
Route::statamic('promocje/feed.xml', 'feeds/promotions', ['layout' => null, 'content_type' => 'rss']);

Route::get('og/program-cpn.png', [OgImageController::class, 'programCpn']);

Route::get('sitemap-cpn.xml', [CpnController::class, 'sitemap']);
Route::get('dane-cpn', [CpnController::class, 'docs']);
Route::get('program-cpn/dane.csv', [CpnController::class, 'csv']);
Route::get('program-cpn/dane.json', [CpnController::class, 'json']);
Route::get('program-cpn/widget', [CpnController::class, 'widget']);
Route::get('program-cpn/jutro', [CpnController::class, 'tomorrow']);
Route::get('program-cpn/obwieszczenia', [CpnController::class, 'notices']);
Route::get('program-cpn/{fuel}', [CpnController::class, 'fuel'])
    ->whereIn('fuel', ['benzyna-95', 'benzyna-98', 'olej-napedowy']);
Route::get('program-cpn/{date}', [CpnController::class, 'day'])
    ->where('date', '20\d{2}-\d{2}-\d{2}');

Route::permanentRedirect('blog/cpn-3-tabela-dzien-po-dniu-3-14-pazdziernika', '/program-cpn/obwieszczenia');
Route::permanentRedirect('blog/cpn-3-tabela-cen-maksymalnych-3-19-pazdziernika-2026', '/program-cpn/obwieszczenia');

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
