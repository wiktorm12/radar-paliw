<?php

namespace Tests\Feature;

use App\Http\Middleware\NormalizeUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

class SeoInfrastructureTest extends TestCase
{
    public function test_trailing_and_double_slashes_redirect_permanently(): void
    {
        // The test client trims trailing slashes from URIs, so call the middleware directly.
        foreach ([
            ['/promocje/', 'http://localhost/promocje'],
            ['/promocje//', 'http://localhost/promocje'],
            ['/blog/?stacja=bp', 'http://localhost/blog?stacja=bp'],
        ] as [$uri, $expected]) {
            $response = (new NormalizeUrl)->handle(Request::create('http://localhost'.$uri), fn () => response('next'));

            $this->assertSame(301, $response->getStatusCode());
            $this->assertSame($expected, $response->headers->get('Location'));
        }

        $this->assertSame(200, (new NormalizeUrl)->handle(Request::create('http://localhost/'), fn () => response('next'))->getStatusCode());
    }

    public function test_cp_paths_are_not_redirected(): void
    {
        $this->get('/cp/auth/login')->assertOk();
    }

    public function test_invalid_page_param_is_stripped(): void
    {
        foreach (['1', '0', '-3', 'abc'] as $page) {
            $this->assertRedirectsTo('http://localhost/promocje?page='.$page.'&stacja=bp', '/promocje?stacja=bp');
        }

        $this->assertRedirectsTo('http://localhost/promocje?page=1', '/promocje');
    }

    public function test_robots_in_production_lists_ai_bots_and_https_sitemap(): void
    {
        config(['app.url' => 'https://radarpaliw.pl/']);
        $this->app->detectEnvironment(fn () => 'production');

        $body = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Sitemap: https://radarpaliw.pl/sitemap.xml', $body);
        $this->assertStringContainsString("User-agent: OAI-SearchBot\nAllow: /\nDisallow: /cp", $body);
        $this->assertStringContainsString("User-agent: ClaudeBot\nAllow: /\nDisallow: /cp", $body);
        $this->assertStringNotContainsString("Disallow: /\n", str_replace('Disallow: /cp', '', $body));
    }

    public function test_robots_outside_production_blocks_everything(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /', false);
    }

    public function test_public_page_is_cacheable_without_cookies(): void
    {
        $response = $this->get('/promocje')->assertOk();

        $cache = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('s-maxage=3600', $cache);
        $this->assertSame([], $response->headers->getCookies());
        $response->assertHeader('Content-Language', 'pl');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_cp_still_sets_session_cookie_and_is_noindex(): void
    {
        $response = $this->get('/cp/auth/login')->assertOk();

        $names = array_map(fn ($c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains(config('session.cookie'), $names);
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_llms_txt_is_plain_text(): void
    {
        $response = $this->get('/llms.txt')->assertOk();

        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('# Radar Paliw', $response->getContent());
        $this->assertStringContainsString('## Aktywne promocje', $response->getContent());
    }

    public function test_articles_sitemap_is_valid_xml_with_images(): void
    {
        $response = $this->get('/sitemap-articles.xml')->assertOk();

        $this->assertStringStartsWith('text/xml', $response->headers->get('Content-Type'));
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertGreaterThan(200, count($xml->url));
        $this->assertStringContainsString('<image:loc>', $response->getContent());

        $this->get('/sitemap-unknown.xml')->assertNotFound();
    }

    private function assertRedirectsTo(string $url, string $pathAndQuery): void
    {
        $response = $this->get($url)->assertStatus(301);

        $this->assertStringEndsWith($pathAndQuery, $response->headers->get('Location'));
    }
}
