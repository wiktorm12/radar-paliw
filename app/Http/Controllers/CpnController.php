<?php

namespace App\Http\Controllers;

use App\Cpn\CpnData;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Statamic\Facades\Cascade;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\View\View;

/**
 * Podstrony programu CPN renderowane z szablonów Statamic. Kaskada ładuje wpis huba
 * /program-cpn, więc layout, SEO i okruszki działają jak na zwykłej stronie.
 */
class CpnController extends Controller implements HasMiddleware
{
    private const CACHE_CONTROL = 'public, max-age=300, s-maxage=3600, stale-while-revalidate=86400';

    public static function middleware(): array
    {
        return ['statamic.web'];
    }

    public function tomorrow(): Response
    {
        return $this->page('templates/cpn/tomorrow', [
            'cpn_page' => 'tomorrow',
            'breadcrumb_title' => 'Cena na jutro',
        ]);
    }

    public function fuel(string $fuel): Response
    {
        $page = CpnData::FUEL_PAGES[$fuel];

        return $this->page('templates/cpn/fuel', [
            'cpn_page' => 'fuel',
            'fuel_slug' => $fuel,
            'breadcrumb_title' => $page['label'],
        ]);
    }

    public function day(string $date): Response
    {
        abort_unless(CpnData::find($date), 404);

        return $this->page('templates/cpn/day', [
            'cpn_page' => 'day',
            'cpn_date' => $date,
            'breadcrumb_title' => Carbon::parse($date)->format('j.m.Y'),
        ]);
    }

    public function notices(): Response
    {
        return $this->page('templates/cpn/notices', [
            'cpn_page' => 'notices',
            'breadcrumb_title' => 'Obwieszczenia',
        ]);
    }

    public function widget(): Response
    {
        $html = (new View)->template('templates/cpn/widget')->layout(null)->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => 'frame-ancestors *',
            'X-Robots-Tag' => 'noindex, follow',
        ]);
    }

    public function csv(): Response
    {
        $lines = [['data', 'pb95', 'pb98', 'on', 'zrodlo', 'zrodlo_url']];

        foreach (CpnData::rows('max') as $row) {
            $lines[] = [$row['date'], $row['pb95'], $row['pb98'], $row['on'], $row['source_name'], $row['source_url']];
        }

        $handle = fopen('php://temp', 'r+');

        foreach ($lines as $line) {
            fputcsv($handle, array_map(fn ($value) => $value === null ? '' : (is_float($value) ? number_format($value, 2, '.', '') : $value), $line), escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="ceny-maksymalne-cpn.csv"',
            'Cache-Control' => self::CACHE_CONTROL,
            'Link' => '<'.CpnData::LICENSE_URL.'>; rel="license"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function json(): Response
    {
        $base = rtrim(Site::default()->absoluteUrl(), '/');

        $body = [
            'name' => 'Ceny maksymalne paliw w programie CPN (Ceny Paliwa Niżej)',
            'description' => 'Dzienne ceny maksymalne benzyny 95, benzyny 98 i oleju napędowego z obwieszczeń Ministra Energii, III edycja programu CPN.',
            'url' => "{$base}/program-cpn",
            'license' => CpnData::LICENSE_URL,
            'attribution' => 'Źródło: Radar Paliw (radarpaliw.pl/program-cpn) na podstawie obwieszczeń Ministra Energii',
            'unit' => 'PLN/l brutto',
            'updated' => CpnData::modified('notices'),
            'data' => CpnData::rows('max')->map(fn ($row) => [
                'date' => $row['date'],
                'pb95' => $row['pb95'],
                'pb98' => $row['pb98'],
                'on' => $row['on'],
                'source_name' => $row['source_name'],
                'source_url' => $row['source_url'],
                'url' => "{$base}/program-cpn/{$row['date']}",
            ])->all(),
        ];

        return response(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => self::CACHE_CONTROL,
            'Link' => '<'.CpnData::LICENSE_URL.'>; rel="license"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function sitemap(): Response
    {
        $base = rtrim(Site::default()->absoluteUrl(), '/').'/program-cpn';
        $max = CpnData::rows('max');

        $urls = collect([
            ['loc' => "{$base}/jutro", 'lastmod' => CpnData::modified('tomorrow')],
            ...collect(CpnData::FUEL_PAGES)->keys()->map(fn ($slug) => ['loc' => "{$base}/{$slug}", 'lastmod' => CpnData::modified('fuel')]),
            ['loc' => "{$base}/obwieszczenia", 'lastmod' => CpnData::modified('notices')],
            ...$max->sortByDesc('date')->map(fn ($row) => [
                'loc' => "{$base}/{$row['date']}",
                'lastmod' => CpnData::iso(Carbon::createFromTimestamp($row['modified'])),
            ]),
        ]);

        return response()
            ->view('sitemaps.cpn', ['urls' => $urls])
            ->header('Content-Type', 'text/xml; charset=UTF-8');
    }

    private function page(string $template, array $data): Response
    {
        Cascade::withRequest(request());

        $html = (new View)
            ->template($template)
            ->layout('layout')
            ->with($data)
            ->cascadeContent(Entry::find(CpnData::HUB_ID))
            ->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
