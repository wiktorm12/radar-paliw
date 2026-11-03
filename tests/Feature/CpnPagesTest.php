<?php

namespace Tests\Feature;

use Statamic\Facades\Site;
use Tests\TestCase;

class CpnPagesTest extends TestCase
{
    public function test_subpages_render_with_own_title_canonical_and_breadcrumb(): void
    {
        foreach ([
            '/program-cpn/jutro' => 'Cena na jutro',
            '/program-cpn/benzyna-95' => 'Benzyna 95',
            '/program-cpn/benzyna-98' => 'Benzyna 98',
            '/program-cpn/olej-napedowy' => 'Olej napędowy',
            '/program-cpn/obwieszczenia' => 'Obwieszczenia',
            '/program-cpn/2026-10-03' => '3.10.2026',
        ] as $uri => $crumb) {
            $html = $this->get($uri)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#rel="canonical"\s+href="'.preg_quote($this->siteUrl().$uri, '#').'"#', $html, $uri);
            $this->assertSame(1, substr_count($html, '<h1'), $uri);

            $breadcrumbs = $this->jsonLd($html, 'BreadcrumbList');
            $last = end($breadcrumbs['itemListElement']);
            $this->assertSame(3, $last['position'], $uri);
            $this->assertSame($crumb, $last['name'], $uri);
            $this->assertSame($this->siteUrl().$uri, $last['item'], $uri);

            $webPage = collect($this->jsonLd($html, '@graph')['@graph'])->firstWhere('@type', 'WebPage');
            $this->assertSame($this->siteUrl().$uri.'#webpage', $webPage['@id'], $uri);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $webPage['dateModified'], $uri);
        }
    }

    public function test_day_page_title_contains_date_and_unknown_dates_are_404(): void
    {
        $this->get('/program-cpn/2026-10-03')
            ->assertOk()
            ->assertSee('<title>Ceny maksymalne paliw 3.10.2026 (CPN): Pb95 6,73, ON 7,88 zł', false)
            ->assertSee('pierwszy limit w danych', false);

        $this->get('/program-cpn/2026-01-01')->assertNotFound();
        $this->get('/program-cpn/2026-02-30')->assertNotFound();
        $this->get('/program-cpn/benzyna-99')->assertNotFound();
    }

    public function test_hub_has_dataset_with_license_and_downloads_and_extended_faq(): void
    {
        $html = $this->get('/program-cpn')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<title>Ceny maksymalne paliw \d{1,2}\.\d{2}\.\d{4} – Pb95 \d,\d{2}, ON \d,\d{2} zł \| CPN<\/title>/u', $html);

        $graph = collect($this->jsonLd($html, '@graph')['@graph']);
        $dataset = $graph->firstWhere('@type', 'Dataset');
        $this->assertSame('https://creativecommons.org/licenses/by/4.0/', $dataset['license']);
        $this->assertSame('2026-10-03', $dataset['datePublished']);
        $this->assertSame(['text/csv', 'application/json'], array_column($dataset['distribution'], 'encodingFormat'));
        $this->assertSame($graph->firstWhere('@type', 'WebPage')['dateModified'], $dataset['dateModified']);

        $faq = $graph->firstWhere('@type', 'FAQPage');
        $this->assertGreaterThanOrEqual(12, count($faq['mainEntity']));
        $this->assertContains('Co oznacza skrót CPN?', array_column($faq['mainEntity'], 'name'));

        $this->assertStringContainsString('href="/program-cpn/2026-10-03"', $html);
        $this->assertStringContainsString('id="kalkulator"', $html);
        $this->assertStringContainsString('id="po-koncu-cpn"', $html);
    }

    public function test_fuel_page_has_single_series_chart_and_faq(): void
    {
        $html = $this->get('/program-cpn/olej-napedowy')->assertOk()->getContent();

        preg_match('/data-price-chart-data\s*>(.*?)<\/script>/s', $html, $match);
        $series = json_decode($match[1], true);
        $this->assertSame(['on'], array_column($series['series'], 'key'));

        $faq = collect($this->jsonLd($html, '@graph')['@graph'])->firstWhere('@type', 'FAQPage');
        $this->assertCount(4, $faq['mainEntity']);
        $this->assertStringContainsString('href="/paliwa/on"', $html);
    }

    public function test_csv_and_json_exports(): void
    {
        $csv = $this->get('/program-cpn/dane.csv')->assertOk();
        $this->assertStringStartsWith('text/csv', $csv->headers->get('Content-Type'));
        $lines = explode("\n", trim($csv->getContent()));
        $this->assertSame('data,pb95,pb98,on,zrodlo,zrodlo_url', $lines[0]);
        $this->assertStringStartsWith('2026-10-03,6.73,7.59,7.88,', $lines[1]);

        $json = $this->get('/program-cpn/dane.json')->assertOk();
        $this->assertStringStartsWith('application/json', $json->headers->get('Content-Type'));
        $json->assertJsonPath('license', 'https://creativecommons.org/licenses/by/4.0/')
            ->assertJsonPath('data.0.date', '2026-10-03')
            ->assertJsonPath('data.0.pb95', 6.73);
        $this->assertCount(count($lines) - 1, $json->json('data'));
    }

    public function test_widget_is_embeddable_and_noindex(): void
    {
        $this->get('/program-cpn/widget')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', 'frame-ancestors *')
            ->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertHeaderMissing('X-Frame-Options')
            ->assertSee('href="'.$this->siteUrl().'/program-cpn"', false)
            ->assertDontSee('nofollow', false);
    }

    public function test_cpn_sitemap_lists_subpages_but_not_hub(): void
    {
        $response = $this->get('/sitemap-cpn.xml')->assertOk();

        $this->assertStringStartsWith('text/xml', $response->headers->get('Content-Type'));
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);

        $locs = [];

        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }

        $base = $this->siteUrl().'/program-cpn';

        $this->assertContains("{$base}/jutro", $locs);
        $this->assertContains("{$base}/obwieszczenia", $locs);
        $this->assertContains("{$base}/2026-10-03", $locs);
        $this->assertNotContains($base, $locs);

        foreach ($xml->url as $url) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string) $url->lastmod);
        }
    }

    public function test_old_cpn_table_articles_redirect_to_notices(): void
    {
        foreach ([
            '/blog/cpn-3-tabela-dzien-po-dniu-3-14-pazdziernika',
            '/blog/cpn-3-tabela-cen-maksymalnych-3-19-pazdziernika-2026',
        ] as $uri) {
            $this->get($uri)->assertStatus(301)->assertRedirect('/program-cpn/obwieszczenia');
        }
    }

    private function siteUrl(): string
    {
        return rtrim(Site::default()->absoluteUrl(), '/');
    }

    private function jsonLd(string $html, string $key): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

        foreach ($matches[1] as $json) {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            if (isset($data[$key]) || ($data['@type'] ?? null) === $key) {
                return $data;
            }
        }

        $this->fail("JSON-LD {$key} not found");
    }
}
