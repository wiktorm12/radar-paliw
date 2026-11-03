<?php

namespace Tests\Feature;

use App\Support\Lastmod;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SitemapLastmodTest extends TestCase
{
    private const SM = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_program_cpn_lastmod_is_newest_price_entry(): void
    {
        $expected = $this->newestCpnTimestamp();

        $lastmod = $this->lastmodOf('/sitemap-pages.xml', '/program-cpn');

        $this->assertSame($expected, Carbon::parse($lastmod)->timestamp);
        $this->assertStringEndsWith(Carbon::parse($lastmod)->setTimezone('Europe/Warsaw')->format('P'), $lastmod);
    }

    public function test_index_lastmod_is_newest_url_of_each_sitemap(): void
    {
        $index = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());
        $lastmods = [];

        foreach ($index->sitemap as $sitemap) {
            $lastmods[basename((string) $sitemap->loc)] = (string) $sitemap->lastmod;
        }

        foreach (['pages', 'promotions', 'stations', 'articles', 'authors'] as $name) {
            $urls = simplexml_load_string($this->get("/sitemap-{$name}.xml")->getContent())->url;
            $newest = 0;

            foreach ($urls as $url) {
                $newest = max($newest, Carbon::parse((string) $url->lastmod)->timestamp);
            }

            $this->assertSame($newest, Carbon::parse($lastmods["sitemap-{$name}.xml"])->timestamp, $name);
        }

        $this->assertSame($lastmods['sitemap-promotions.xml'], $lastmods['sitemap-paliwa.xml']);
        $this->assertSame($lastmods['sitemap-articles.xml'], $lastmods['sitemap-kategorie.xml']);
        $this->assertSame($this->newestCpnTimestamp(), Carbon::parse($lastmods['sitemap-cpn.xml'])->timestamp);
        $this->assertArrayHasKey('sitemap-news.xml', $lastmods);
    }

    public function test_taxonomy_sitemaps_list_terms_with_lastmod(): void
    {
        foreach (['paliwa', 'kategorie'] as $taxonomy) {
            $response = $this->get("/sitemap-{$taxonomy}.xml")->assertOk();
            $this->assertStringStartsWith('text/xml', $response->headers->get('Content-Type'));

            $urls = simplexml_load_string($response->getContent())->url;

            $this->assertGreaterThan(3, count($urls));

            foreach ($urls as $url) {
                $this->assertNotEmpty((string) $url->lastmod);
            }
        }
    }

    public function test_news_sitemap_has_only_recent_articles_in_google_news_format(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00', 'Europe/Warsaw'));

        $response = $this->get('/sitemap-news.xml')->assertOk();
        $this->assertStringStartsWith('text/xml', $response->headers->get('Content-Type'));

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertStringContainsString('xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"', $response->getContent());
        $this->assertGreaterThan(0, count($xml->url));

        foreach ($xml->url as $url) {
            $news = $url->children('news', true)->news;

            $this->assertSame('Radar Paliw', (string) $news->publication->name);
            $this->assertSame('pl', (string) $news->publication->language);
            $this->assertNotSame('', (string) $news->title);
            $this->assertStringContainsString('/blog/', (string) $url->loc);

            $published = substr((string) $news->publication_date, 0, 10);
            $this->assertGreaterThanOrEqual('2026-11-01', $published);
            $this->assertLessThanOrEqual('2026-11-03', $published);
        }
    }

    private function lastmodOf(string $sitemap, string $path): string
    {
        foreach (simplexml_load_string($this->get($sitemap)->getContent())->url as $url) {
            if (parse_url((string) $url->loc, PHP_URL_PATH) === $path) {
                return (string) $url->lastmod;
            }
        }

        $this->fail("{$path} not found in {$sitemap}");
    }

    /** Independent re-implementation: newest max/outlook entry, date (once started) or manifest, whichever is later. */
    private function newestCpnTimestamp(): int
    {
        $now = time();
        $newest = 0;
        $manifest = Lastmod::parseManifest((string) @file_get_contents(base_path('content/.lastmod')));

        foreach (glob(base_path('content/collections/cpn_prices/*.md')) as $file) {
            if (! preg_match('/"type":\s*"(max|outlook)"/', file_get_contents($file))) {
                continue;
            }

            $key = 'content/collections/cpn_prices/'.basename($file);
            $candidate = isset($manifest[$key]) ? strtotime($manifest[$key]) : 0;
            $start = Carbon::parse(substr(basename($file), 0, 10), 'Europe/Warsaw')->startOfDay()->timestamp;

            if ($start <= $now && $start > $candidate) {
                $candidate = $start;
            }

            $newest = max($newest, $candidate);
        }

        return $newest;
    }
}
