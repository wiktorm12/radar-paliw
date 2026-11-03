<?php

namespace Tests\Feature;

use App\Support\CpnPrices;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CpnInfrastructureTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        gc_collect_cycles();
    }

    public function test_og_image_is_a_cached_1200x630_png(): void
    {
        $response = $this->get('/og/program-cpn.png?v=123')->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=3600', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());

        [$width, $height] = getimagesizefromstring($response->getContent());
        $this->assertSame([1200, 630], [$width, $height]);

        $this->assertSame($response->getContent(), $this->get('/og/program-cpn.png')->getContent());
    }

    public function test_uppercase_paths_redirect_to_lowercase(): void
    {
        $response = $this->get('/Program-CPN?Foo=Bar')->assertStatus(301);
        $this->assertStringEndsWith('/program-cpn?Foo=Bar', $response->headers->get('Location'));

        $this->get('/Blog/Feed')->assertStatus(301)->assertRedirectContains('/blog/feed');
        $this->get('/program-cpn')->assertOk();
    }

    public function test_static_assets_and_cp_are_not_lowercased(): void
    {
        $this->get('/IMG/Logo')->assertStatus(301);

        foreach (['/Sitemap.XML', '/img/Logo.PNG', '/og/Program-CPN.png'] as $uri) {
            $this->assertNotSame(301, $this->get($uri)->getStatusCode(), $uri);
        }

        $this->get('/cp/auth/login')->assertOk();
    }

    public function test_html_pages_have_etag_answer_304_and_cpn_has_shorter_shared_cache(): void
    {
        $first = $this->get('/o-nas')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('s-maxage=3600', $first->headers->get('Cache-Control'));

        $this->get('/o-nas', ['If-None-Match' => $etag])->assertStatus(304);

        $this->assertStringContainsString('s-maxage=600', $this->get('/program-cpn')->headers->get('Cache-Control'));
    }

    public function test_cloudflare_purge_without_config_warns_and_succeeds(): void
    {
        config(['services.cloudflare.zone_id' => null, 'services.cloudflare.api_token' => null]);
        Http::fake();

        $this->artisan('cloudflare:purge')->expectsOutputToContain('nie jest skonfigurowany')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_cloudflare_purge_sends_default_urls(): void
    {
        config(['services.cloudflare.zone_id' => 'zone1', 'services.cloudflare.api_token' => 'tok', 'app.url' => 'https://radarpaliw.pl/']);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->artisan('cloudflare:purge')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone1/purge_cache'
                && $request->hasHeader('Authorization', 'Bearer tok')
                && in_array('https://radarpaliw.pl/program-cpn', $request['files'], true)
                && in_array('https://radarpaliw.pl/', $request['files'], true)
                && in_array('https://radarpaliw.pl/og/program-cpn.png', $request['files'], true)
                && count($request['files']) === 14;
        });
    }

    public function test_cloudflare_purge_all_and_custom_urls_and_failure(): void
    {
        config(['services.cloudflare.zone_id' => 'zone1', 'services.cloudflare.api_token' => 'tok', 'app.url' => 'https://radarpaliw.pl']);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->artisan('cloudflare:purge', ['--all' => true])->assertExitCode(0);
        Http::assertSent(fn ($request) => $request['purge_everything'] === true);

        $this->artisan('cloudflare:purge', ['urls' => ['/blog', 'https://radarpaliw.pl/x']])->assertExitCode(0);
        Http::assertSent(fn ($request) => ($request['files'] ?? null) === ['https://radarpaliw.pl/blog', 'https://radarpaliw.pl/x']);

    }

    public function test_cloudflare_purge_reports_api_failure(): void
    {
        config(['services.cloudflare.zone_id' => 'zone1', 'services.cloudflare.api_token' => 'tok']);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 1]]], 403)]);

        $this->artisan('cloudflare:purge')->assertExitCode(1);
    }

    public function test_telegram_without_config_warns_and_succeeds(): void
    {
        config(['services.telegram.bot_token' => null, 'services.telegram.chat_id' => null]);
        Http::fake();

        $this->artisan('cpn:telegram')->expectsOutputToContain('nie jest skonfigurowany')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_telegram_sends_latest_prices_once_per_date(): void
    {
        Storage::fake('local');
        config(['services.telegram.bot_token' => 'abc', 'services.telegram.chat_id' => '@kanal']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $latest = CpnPrices::latest();

        $this->artisan('cpn:telegram')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($latest) {
            return $request->url() === 'https://api.telegram.org/botabc/sendMessage'
                && $request['chat_id'] === '@kanal'
                && str_contains($request['text'], 'Benzyna 95: <b>'.CpnPrices::money($latest['fuels']['pb95']['price']).' zł/l</b>')
                && str_contains($request['text'], 'utm_source=telegram&amp;utm_medium=social');
        });

        $this->artisan('cpn:telegram')->expectsOutputToContain('już wysłane')->assertExitCode(0);
        Http::assertSentCount(1);

        $this->artisan('cpn:telegram', ['--force' => true])->assertExitCode(0);
        Http::assertSentCount(2);
    }

    public function test_telegram_failure_does_not_mark_date_as_sent(): void
    {
        Storage::fake('local');
        config(['services.telegram.bot_token' => 'abc', 'services.telegram.chat_id' => '@kanal']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'nope'], 400)]);

        $this->artisan('cpn:telegram')->assertExitCode(1);

        Storage::disk('local')->assertMissing('telegram/cpn-last-date');
    }
}
