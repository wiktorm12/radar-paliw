<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CloudflarePurge extends Command
{
    protected $signature = 'cloudflare:purge
        {urls?* : Adresy lub ścieżki do wyczyszczenia (domyślnie lista stron CPN, mapy witryny i blog)}
        {--all : Wyczyść cały cache strefy (purge_everything)}';

    protected $description = 'Czyści cache Cloudflare dla wskazanych adresów albo całej strefy';

    private const DEFAULT_PATHS = [
        '/',
        '/program-cpn',
        '/program-cpn/jutro',
        '/program-cpn/benzyna-95',
        '/program-cpn/benzyna-98',
        '/program-cpn/olej-napedowy',
        '/program-cpn/obwieszczenia',
        '/og/program-cpn.png',
        '/sitemap.xml',
        '/sitemap-cpn.xml',
        '/sitemap-pages.xml',
        '/sitemap-news.xml',
        '/blog',
        '/blog/feed.xml',
    ];

    public function handle(): int
    {
        $zone = config('services.cloudflare.zone_id');
        $token = config('services.cloudflare.api_token');

        if (! $zone || ! $token) {
            $this->warn('Cloudflare nie jest skonfigurowany (CLOUDFLARE_ZONE_ID / CLOUDFLARE_API_TOKEN) — pomijam.');

            return self::SUCCESS;
        }

        $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache";

        if ($this->option('all')) {
            return $this->send($endpoint, $token, ['purge_everything' => true]) ? self::SUCCESS : self::FAILURE;
        }

        $base = rtrim((string) config('app.url'), '/');
        $paths = $this->argument('urls') ?: self::DEFAULT_PATHS;
        $urls = array_values(array_unique(array_map(
            fn (string $url) => preg_match('#^https?://#i', $url) ? $url : $base.'/'.ltrim($url, '/'),
            $paths,
        )));

        foreach (array_chunk($urls, 30) as $chunk) {
            if (! $this->send($endpoint, $token, ['files' => $chunk])) {
                return self::FAILURE;
            }
        }

        $this->info(count($urls).' adresów wyczyszczonych w Cloudflare.');

        return self::SUCCESS;
    }

    private function send(string $endpoint, string $token, array $payload): bool
    {
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(15)->post($endpoint, $payload);
        } catch (\Throwable $e) {
            $this->error('Cloudflare: '.$e->getMessage());

            return false;
        }

        if (! $response->successful() || $response->json('success') === false) {
            $this->error('Cloudflare odrzucił żądanie (HTTP '.$response->status().'): '.json_encode($response->json('errors')));

            return false;
        }

        if (isset($payload['purge_everything'])) {
            $this->info('Cały cache strefy Cloudflare wyczyszczony.');
        }

        return true;
    }
}
