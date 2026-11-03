<?php

namespace Tests\Feature;

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class SeoOverridesTest extends TestCase
{
    public function test_template_sections_override_modified_date_and_og_image(): void
    {
        $this->registerFixtureRoute();

        $html = $this->get('/seo-fixture')->assertOk()->getContent();

        $this->assertStringContainsString('property="og:image"'."\n".'    content="https://example.com/og/test.png"', $html);
        $this->assertStringContainsString('name="twitter:image"'."\n".'    content="https://example.com/og/test.png"', $html);
        $this->assertSame(1, substr_count($html, 'property="og:image"'));

        $graph = $this->webPageNode($html);
        $this->assertSame('2026-11-03T08:15:00+01:00', $graph['dateModified']);
        $this->assertSame('https://example.com/og/test.png', $graph['primaryImageOfPage']['url']);
        $this->assertSame('ImageObject', $graph['primaryImageOfPage']['@type']);
    }

    public function test_without_sections_page_uses_file_date_and_default_og_image(): void
    {
        $html = $this->get('/blog')->assertOk()->getContent();

        $graph = $this->webPageNode($html);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+0[12]:00$/', $graph['dateModified']);
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringNotContainsString('example.com/og/test.png', $html);
        $this->assertNotEmpty($graph['primaryImageOfPage']['url']);
    }

    public function test_article_modified_time_matches_json_ld_date_modified(): void
    {
        $url = $this->firstArticleUrl();
        $html = $this->get($url)->assertOk()->getContent();

        preg_match('/property="article:modified_time"\s+content="([^"]+)"/', $html, $meta);

        $this->assertNotEmpty($meta);
        $this->assertSame($meta[1], $this->webPageNode($html)['dateModified']);
    }

    public function test_blog_title_has_brand_suffix(): void
    {
        $this->get('/blog')->assertOk()->assertSee('<title>Blog o paliwach: poradniki i programy stacji | Radar Paliw</title>', false);
    }

    public function test_long_description_is_cut_at_sentence_boundary(): void
    {
        $html = $this->get('/kategorie/aplikacje')->assertOk()->getContent();

        preg_match('/name="description"\s+content="([^"]*)"/', $html, $match);

        $this->assertLessThanOrEqual(155, mb_strlen($match[1]));
        $this->assertStringEndsWith('.', $match[1]);
        $this->assertStringNotContainsString('…', $match[1]);
    }

    private function webPageNode(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $blocks);

        foreach ($blocks[1] as $block) {
            foreach (json_decode($block, true)['@graph'] ?? [] as $node) {
                if (str_ends_with($node['@id'] ?? '', '#webpage')) {
                    return $node;
                }
            }
        }

        $this->fail('No WebPage node in JSON-LD.');
    }

    private function firstArticleUrl(): string
    {
        $xml = simplexml_load_string($this->get('/sitemap-articles.xml')->getContent());

        return parse_url((string) $xml->url[0]->loc, PHP_URL_PATH);
    }

    private function registerFixtureRoute(): void
    {
        View::addLocation(base_path('tests/Fixtures/views'));
        $fixture = Route::statamic('/seo-fixture', 'seo-fixture', ['load' => 'page-blog']);

        // Statamic's catch-all is registered first and would swallow the fixture route.
        $routes = new RouteCollection;
        $routes->add($fixture);

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route !== $fixture) {
                $routes->add($route);
            }
        }

        Route::setRoutes($routes);
    }
}
