<?php

namespace App\Tags;

use App\Cpn\CpnData;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Statamic\Facades\Entry;
use Statamic\Tags\Tags;

class Cpn extends Tags
{
    public const FUELS = [
        'pb95' => 'Benzyna 95',
        'pb98' => 'Benzyna 98',
        'on' => 'Olej napędowy',
        'lpg' => 'LPG',
    ];

    /** Stała na koszty stacji (zł/l netto) we wzorze ceny maksymalnej. */
    public const STATION_MARGIN = 0.30;

    /** Mnożnik VAT 8% obowiązujący w programie CPN. */
    public const VAT = 1.08;

    /** Mnożnik standardowego VAT 23% (stawka sprzed programu). */
    public const VAT_FULL = 1.23;

    public const WEEKDAY_ACCUSATIVE = [1 => 'poniedziałek', 'wtorek', 'środę', 'czwartek', 'piątek', 'sobotę', 'niedzielę'];

    /** Obniżka akcyzy w III edycji w zł/l: benzyny 1529 → 1239, ON 1160 → 880 zł/1000 l. */
    public const EXCISE_RETURN = ['pb95' => 0.29, 'pb98' => 0.29, 'on' => 0.28];

    /** Ile dni naprzód (od ostatniego oficjalnego limitu) sięga projekcja trendu. */
    public const PROJECTION_DAYS = 7;

    /** Ile ostatnich notowań hurtu (dni robocze) wyznacza trend. */
    public const TREND_WINDOW = 10;

    /** Wygaszanie trendu na każde kolejne notowanie — dalsze dni zakładają coraz mniejszą zmianę. */
    public const TREND_DAMPING = 0.8;

    /** Kwantyl rozkładu normalnego dla przedziału 80%. */
    public const BAND_Z = 1.28;

    /** Zmiana poniżej tylu zł/tydzień to trend boczny. */
    public const TREND_FLAT = 0.02;

    /** Analiza rynku starsza o tyle dni od ostatniego limitu nie wpływa już na prognozę. */
    public const OUTLOOK_MAX_AGE = 2;

    /**
     * Prognoza kolejnej ceny maksymalnej z ostatniej ceny hurtowej Orlenu:
     * (hurt netto + 0,30 zł) × 1,08. Znika, gdy ministerstwo opublikuje limit.
     */
    public function forecast()
    {
        $hurt = $this->rows('hurt')->last();

        if (! $hurt) {
            return $this->parseNoResults();
        }

        $max = $this->rows('max');
        $dates = $this->limitDates($hurt['date']);

        if ($max->contains(fn ($row) => $row['date'] >= $dates[0])) {
            return $this->parseNoResults();
        }

        $latest = CpnData::current($max);

        return [
            'date' => $dates[0],
            'date_end' => end($dates),
            'hurt_date' => $hurt['date'],
            ...$this->flatPrices(collect(['pb95', 'pb98', 'on'])->mapWithKeys(fn ($fuel) => [$fuel => $hurt[$fuel] === null ? null : $this->predict($hurt[$fuel])])->all()),
            'source_url' => $hurt['source_url'],
            'source_name' => $hurt['source_name'],
            'fuels' => collect(['pb95', 'pb98', 'on'])
                ->filter(fn ($fuel) => $hurt[$fuel] !== null)
                ->map(fn ($fuel) => [
                    'key' => $fuel,
                    'label' => self::FUELS[$fuel],
                    'price' => $this->money($this->predict($hurt[$fuel])),
                    'hurt' => $this->money($hurt[$fuel]),
                    ...$this->delta($this->predict($hurt[$fuel]), $latest[$fuel] ?? null),
                ])->values()->all(),
            ...$this->accuracy($max),
        ];
    }

    /**
     * Trend i projekcja limitu na 7 dni: najbliższe dni ze wzoru z hurtu, dalej
     * wygaszany trend liniowy z ostatnich notowań hurtu, z przedziałem 80%.
     */
    public function trend()
    {
        $projection = $this->projection();

        if (! $projection) {
            return $this->parseNoResults();
        }

        $latest = CpnData::current($this->rows('max'));
        $lastDay = end($projection['days']);

        return [
            'start_date' => $projection['days'][0]['date'],
            'end_date' => $lastDay['date'],
            'window' => $projection['window'],
            'window_start' => $projection['window_start'],
            'outlook' => $projection['outlook'] !== null,
            'outlook_date' => $projection['outlook']['date'] ?? null,
            'summary' => $projection['outlook']['summary'] ?? null,
            'factors' => $projection['outlook']['factors'] ?? [],
            'source_name' => $projection['outlook']['source_name'] ?? null,
            'source_url' => $projection['outlook']['source_url'] ?? null,
            'fuels' => collect($projection['fuels'])->map(fn ($fuel, $key) => [
                'key' => $key,
                'label' => self::FUELS[$key],
                'direction' => $fuel['direction'],
                'weekly' => $this->signedGrosze($fuel['weekly']),
                'market' => isset($fuel['market']) ? $this->signedGrosze($fuel['market']) : null,
                'price' => $this->money($lastDay['values'][$key]['price']),
                'low' => $this->money($lastDay['values'][$key]['low']),
                'high' => $this->money($lastDay['values'][$key]['high']),
                ...$this->delta($lastDay['values'][$key]['price'], $latest[$key] ?? null),
            ])->values()->all(),
            'days' => collect($projection['days'])->map(fn ($day) => [
                'date' => $day['date'],
                'kind' => $day['kind'],
                'cells' => collect($day['values'])->map(fn ($value, $key) => [
                    'key' => $key,
                    'price' => $this->money($value['price']),
                    'low' => $this->money($value['low']),
                    'high' => $this->money($value['high']),
                ])->values()->all(),
            ])->all(),
        ];
    }

    public function latest()
    {
        $rows = $this->rows($this->params->get('type', 'max'));
        $current = CpnData::current($rows);

        if (! $current) {
            return $this->parseNoResults();
        }

        // Ministerstwo bywa, że publikuje limity z góry na kilka dni — „aktualny” to dzisiejszy, nie najnowszy.
        $index = $rows->search(fn ($row) => $row['date'] === $current['date']);
        $previous = $index > 0 ? $rows->get($index - 1) : null;
        // Tylko potwierdzony (już opublikowany) limit na dzień po dzisiejszym — bez prognoz.
        $tomorrow = $rows->firstWhere('date', Carbon::parse($current['date'])->addDay()->toDateString());

        return [
            'date' => $current['date'],
            'source_url' => $current['source_url'],
            'source_name' => $current['source_name'],
            'tomorrow_date' => $tomorrow['date'] ?? null,
            ...$this->flatPrices($current),
            'fuels' => $this->fuelsIn($rows)->map(fn ($label, $fuel) => [
                'key' => $fuel,
                'label' => $label,
                'price' => $this->money($current[$fuel]),
                ...$this->delta($current[$fuel], $previous[$fuel] ?? null),
                'tomorrow_price' => $this->money($tomorrow[$fuel] ?? null),
                ...collect($this->delta($tomorrow[$fuel] ?? null, $current[$fuel]))
                    ->mapWithKeys(fn ($value, $key) => ["tomorrow_{$key}" => $value])->all(),
            ])->filter(fn ($fuel) => $fuel['price'] !== null)->values()->all(),
        ];
    }

    public function stats()
    {
        $rows = $this->untilToday($this->rows($this->params->get('type', 'max')));

        return $this->fuelsIn($rows)->map(function ($label, $fuel) use ($rows) {
            $points = $rows->filter(fn ($row) => $row[$fuel] !== null)->values();

            if ($points->isEmpty()) {
                return null;
            }

            $first = $points->first();
            $last = $points->last();
            $min = $points->sortBy($fuel)->first();
            $max = $points->sortByDesc($fuel)->first();

            return [
                'key' => $fuel,
                'label' => $label,
                'start_price' => $this->money($first[$fuel]),
                'start_date' => $first['date'],
                'end_price' => $this->money($last[$fuel]),
                'end_date' => $last['date'],
                'min' => $this->money($min[$fuel]),
                'min_date' => $min['date'],
                'max' => $this->money($max[$fuel]),
                'max_date' => $max['date'],
                'avg' => $this->money($points->avg($fuel)),
                'days' => $points->count(),
                'tank_50' => $this->money($last[$fuel] * 50),
                ...$this->delta($last[$fuel], $first[$fuel]),
            ];
        })->filter()->values()->all();
    }

    public function table()
    {
        $rows = $this->rows($this->params->get('type', 'max'));
        $fuels = $this->fuelsIn($rows)->keys();

        return $rows->values()->map(function ($row, $index) use ($rows, $fuels) {
            $previous = $index > 0 ? $rows->get($index - 1) : null;

            return [
                'date' => $row['date'],
                'day_url' => $this->params->get('type', 'max') === 'max' ? '/program-cpn/'.$row['date'] : null,
                'source_url' => $row['source_url'],
                'source_name' => $row['source_name'],
                'cells' => $fuels->map(fn ($fuel) => [
                    'key' => $fuel,
                    'price' => $this->money($row[$fuel]),
                    ...$this->delta($row[$fuel], $previous[$fuel] ?? null),
                ])->all(),
            ];
        })->reverse()->values()->all();
    }

    public function columns()
    {
        return $this->fuelsIn($this->rows($this->params->get('type', 'max')))
            ->map(fn ($label, $fuel) => ['key' => $fuel, 'label' => $label])
            ->values()
            ->all();
    }

    public function series()
    {
        $rows = $this->rows($this->params->get('type', 'max'));
        $projection = $this->params->bool('projection') ? $this->projection() : null;

        return json_encode([
            'dates' => $rows->pluck('date')->all(),
            'series' => $this->fuelsIn($rows)->map(fn ($label, $fuel) => [
                'key' => $fuel,
                'label' => $label,
                'values' => $rows->pluck($fuel)->all(),
            ])->values()->all(),
            'projection' => $projection ? [
                'dates' => array_column($projection['days'], 'date'),
                'kinds' => array_column($projection['days'], 'kind'),
                'method' => $projection['outlook'] ? 'market' : 'trend',
                'series' => collect($projection['fuels'])->only($this->fuelsIn($rows)->keys()->all())->map(fn ($fuel, $key) => [
                    'key' => $key,
                    'values' => array_map(fn ($day) => $day['values'][$key]['price'], $projection['days']),
                    'low' => array_map(fn ($day) => $day['values'][$key]['low'], $projection['days']),
                    'high' => array_map(fn ($day) => $day['values'][$key]['high'], $projection['days']),
                ])->values()->all(),
            ] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    }

    public function meta()
    {
        $rows = $this->rows($this->params->get('type', 'max'));

        if ($rows->isEmpty()) {
            return $this->parseNoResults();
        }

        return [
            'start_date' => $rows->first()['date'],
            'end_date' => $rows->last()['date'],
            'days' => $rows->count(),
            'sources' => $rows->filter(fn ($row) => $row['source_url'])
                ->reverse()
                ->unique('source_name')
                ->map(fn ($row) => ['name' => $row['source_name'], 'url' => $row['source_url']])
                ->values()
                ->all(),
        ];
    }

    public function compare()
    {
        $max = CpnData::current($this->rows('max'));
        $retail = $this->rows('retail');
        $before = $retail->filter(fn ($row) => $max && $row['date'] < $this->rows('max')->first()['date'])->last();

        if (! $max || ! $before) {
            return $this->parseNoResults();
        }

        return [
            'before_date' => $before['date'],
            'before_source' => $before['source_name'],
            'max_date' => $max['date'],
            'fuels' => collect(['pb95', 'pb98', 'on'])
                ->filter(fn ($fuel) => $max[$fuel] !== null && $before[$fuel] !== null)
                ->map(fn ($fuel) => [
                    'key' => $fuel,
                    'label' => self::FUELS[$fuel],
                    'before' => $this->money($before[$fuel]),
                    'now' => $this->money($max[$fuel]),
                    'tank_saving' => $this->money(max(0, ($before[$fuel] - $max[$fuel]) * 50)),
                    ...$this->delta($max[$fuel], $before[$fuel]),
                ])->values()->all(),
        ];
    }

    /** ISO 8601 ostatniej zmiany danych strony CPN (section:seo_modified, Dataset dateModified). */
    public function modified()
    {
        return CpnData::modified($this->params->get('page', 'hub'), $this->params->get('date'));
    }

    /** Data limitu obowiązującego dziś (Y-m-d) — wersja obrazu OG, zmienia się razem z jego treścią. */
    public function latestDate()
    {
        return CpnData::current($this->rows('max'))['date'] ?? '';
    }

    /** „Tankować dziś czy poczekać?”: dzisiejszy limit vs limit na kolejny dzień (oficjalny albo prognoza). */
    public function verdict()
    {
        $max = $this->rows('max');
        $today = CpnData::current($max);
        $next = $today ? $this->nextLimit(Carbon::parse($today['date'])->addDay()->toDateString(), $max) : null;

        if (! $next) {
            return $this->parseNoResults();
        }

        $projection = $this->projection();
        $week = $projection ? end($projection['days']) : null;

        $fuels = collect(['pb95', 'pb98', 'on'])
            ->filter(fn ($fuel) => $today[$fuel] !== null && isset($next['values'][$fuel]))
            ->map(function ($fuel) use ($today, $next, $week) {
                $diff = round($next['values'][$fuel]['price'] - $today[$fuel], 2);
                $weekPrice = $week['values'][$fuel]['price'] ?? null;

                return [
                    'key' => $fuel,
                    'label' => self::FUELS[$fuel],
                    'today_price' => $this->money($today[$fuel]),
                    'next_price' => $this->money($next['values'][$fuel]['price']),
                    'action' => $diff < 0 ? 'wait' : ($diff > 0 ? 'buy' : 'same'),
                    'diff' => $this->signedGrosze($diff),
                    'tank_50' => $this->money(abs($diff) * 50),
                    'week_date' => $week['date'] ?? null,
                    'week_price' => $this->money($weekPrice),
                    ...$this->delta($weekPrice, $today[$fuel]),
                ];
            })->values();

        if ($fuels->isEmpty()) {
            return $this->parseNoResults();
        }

        $actions = $fuels->pluck('action')->unique();

        return [
            'today_date' => $today['date'],
            'date' => $next['date'],
            'kind' => $next['kind'],
            'official' => $next['kind'] === 'official',
            'headline' => $actions->count() === 1 ? $actions->first() : 'mixed',
            'fuels' => $fuels->all(),
        ];
    }

    /** O ile podrożałby dzisiejszy limit przy VAT 23% (i opcjonalnie pełnej akcyzie) przy niezmienionym hurcie. */
    public function vat()
    {
        $today = CpnData::current($this->rows('max'));

        if (! $today) {
            return $this->parseNoResults();
        }

        $fuels = collect(['pb95', 'pb98', 'on'])
            ->filter(fn ($fuel) => $today[$fuel] !== null)
            ->map(function ($fuel) use ($today) {
                $net = $today[$fuel] / self::VAT;
                $vat23 = round($net * self::VAT_FULL, 2);
                $full = round(($net + self::EXCISE_RETURN[$fuel]) * self::VAT_FULL, 2);

                return [
                    'key' => $fuel,
                    'label' => self::FUELS[$fuel],
                    'price' => $this->money($today[$fuel]),
                    'price_raw' => $today[$fuel],
                    'vat23' => $this->money($vat23),
                    'vat23_raw' => $vat23,
                    'vat23_diff' => $this->signedGrosze($vat23 - $today[$fuel]),
                    'vat23_tank_50' => $this->money(($vat23 - $today[$fuel]) * 50),
                    'full' => $this->money($full),
                    'full_diff' => $this->signedGrosze($full - $today[$fuel]),
                    'full_tank_50' => $this->money(($full - $today[$fuel]) * 50),
                    'excise' => $this->money(self::EXCISE_RETURN[$fuel]),
                    'cost_50' => $this->money($today[$fuel] * 50),
                    'cost_50_vat23' => $this->money($vat23 * 50),
                ];
            })->values();

        return [
            'date' => $today['date'],
            'fuels' => $fuels->all(),
            'prices_json' => json_encode($fuels->mapWithKeys(fn ($fuel) => [$fuel['key'] => [
                'label' => $fuel['label'],
                'price' => $fuel['price_raw'],
                'vat23' => $fuel['vat23_raw'],
            ]])->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        ];
    }

    /** Limit na jutro: oficjalny (gdy obwieszczenie już jest) albo prognoza (wzór z hurtu / trend). */
    public function tomorrow()
    {
        $max = $this->rows('max');
        $today = CpnData::current($max);
        $next = $this->nextLimit(CpnData::tomorrow(), $max);

        if (! $next) {
            return $this->parseNoResults();
        }

        return [
            'date' => $next['date'],
            'date_end' => $next['date_end'],
            'multi_day' => $next['date_end'] !== $next['date'],
            'weekday' => $this->weekday($next['date']),
            'weekday_acc' => self::WEEKDAY_ACCUSATIVE[Carbon::parse($next['date'])->dayOfWeekIso],
            'kind' => $next['kind'],
            'official' => $next['kind'] === 'official',
            'source_url' => $next['source_url'],
            'source_name' => $next['source_name'],
            'hurt_date' => $next['hurt_date'],
            'today_date' => $today['date'] ?? null,
            ...$this->flatPrices(array_map(fn ($value) => $value['price'], $next['values'])),
            'fuels' => collect($next['values'])->map(fn ($value, $fuel) => [
                'key' => $fuel,
                'label' => self::FUELS[$fuel],
                'price' => $this->money($value['price']),
                'low' => $this->money($value['low']),
                'high' => $this->money($value['high']),
                'today_price' => $this->money($today[$fuel] ?? null),
                ...$this->delta($value['price'], $today[$fuel] ?? null),
            ])->values()->all(),
        ];
    }

    /** Strona dnia /program-cpn/{data}: ceny, zmiany, obwieszczenie, opis z danych i artykuły. */
    public function day()
    {
        $max = $this->rows('max');
        $date = (string) $this->params->get('date');
        $index = $max->search(fn ($row) => $row['date'] === $date);

        if ($index === false) {
            return $this->parseNoResults();
        }

        $row = $max[$index];
        $previous = $index > 0 ? $max[$index - 1] : null;
        $next = $max[$index + 1] ?? null;
        $start = $max->first();
        $dates = CpnData::noticeDates($row, $max);
        $earlier = $max->slice(0, $index)->values();

        return [
            'date' => $row['date'],
            'weekday' => $this->weekday($row['date']),
            'weekday_acc' => self::WEEKDAY_ACCUSATIVE[Carbon::parse($row['date'])->dayOfWeekIso],
            'weekday_in' => (Carbon::parse($row['date'])->isTuesday() ? 'we ' : 'w ').self::WEEKDAY_ACCUSATIVE[Carbon::parse($row['date'])->dayOfWeekIso],
            'source_url' => $row['source_url'],
            'source_name' => $row['source_name'],
            'notice_start' => $dates[0],
            'notice_end' => end($dates),
            'multi_day' => count($dates) > 1,
            'previous_date' => $previous['date'] ?? null,
            'next_date' => $next['date'] ?? null,
            'start_date' => $start['date'],
            'is_start' => $index === 0,
            ...$this->flatPrices($row),
            'fuels' => collect(['pb95', 'pb98', 'on'])
                ->filter(fn ($fuel) => $row[$fuel] !== null)
                ->map(fn ($fuel) => [
                    'key' => $fuel,
                    'label' => self::FUELS[$fuel],
                    'price' => $this->money($row[$fuel]),
                    'start_price' => $this->money($start[$fuel]),
                    ...$this->delta($row[$fuel], $previous[$fuel] ?? null),
                    ...collect($this->delta($row[$fuel], $index > 0 ? $start[$fuel] : null))
                        ->mapWithKeys(fn ($value, $key) => ["start_{$key}" => $value])->all(),
                    'summary' => $this->daySummary($fuel, $row, $previous, $earlier),
                ])->values()->all(),
            'articles' => $this->articlesAround($row['date']),
        ];
    }

    /** Dane strony paliwa /program-cpn/{benzyna-95|benzyna-98|olej-napedowy}. */
    public function fuel()
    {
        $page = CpnData::FUEL_PAGES[$this->params->get('slug')] ?? null;
        $max = $this->rows('max');
        $today = CpnData::current($max);

        if (! $page || ! $today || $today[$page['key']] === null) {
            return $this->parseNoResults();
        }

        $fuel = $page['key'];
        $index = $max->search(fn ($row) => $row['date'] === $today['date']);
        $previous = $index > 0 ? $max[$index - 1] : null;
        $next = $this->nextLimit(Carbon::parse($today['date'])->addDay()->toDateString(), $max);
        $points = $max->filter(fn ($row) => $row[$fuel] !== null);
        $min = $points->sortBy($fuel)->first();
        $top = $points->sortByDesc($fuel)->first();

        return [
            ...$page,
            'slug' => $this->params->get('slug'),
            'date' => $today['date'],
            'price' => $this->money($today[$fuel]),
            'source_url' => $today['source_url'],
            'source_name' => $today['source_name'],
            ...$this->delta($today[$fuel], $previous[$fuel] ?? null),
            'next_date' => $next['date'] ?? null,
            'next_kind' => $next['kind'] ?? null,
            'next_official' => ($next['kind'] ?? null) === 'official',
            'next_price' => isset($next['values'][$fuel]) ? $this->money($next['values'][$fuel]['price']) : null,
            ...collect($this->delta($next['values'][$fuel]['price'] ?? null, $today[$fuel]))
                ->mapWithKeys(fn ($value, $key) => ["next_{$key}" => $value])->all(),
            'others' => collect(CpnData::FUEL_PAGES)->except($this->params->get('slug'))
                ->map(fn ($other, $slug) => ['url' => "/program-cpn/{$slug}", 'label' => $other['label']])
                ->values()->all(),
            'faq' => $this->fuelFaq($page, $today, $points, $min, $top),
        ];
    }

    /** Archiwum obwieszczeń: wpisy max pogrupowane po źródle (jedno obwieszczenie może obejmować kilka dni). */
    public function notices()
    {
        $notices = CpnData::notices($this->rows('max'));

        if ($notices->isEmpty()) {
            return $this->parseNoResults();
        }

        return [
            'count' => $notices->count(),
            'days' => $notices->sum(fn ($notice) => count($notice['dates'])),
            'start_date' => $notices->last()['date'],
            'end_date' => $notices->first()['date_end'],
            'notices' => $notices->map(fn ($notice) => [
                'date' => $notice['date'],
                'date_end' => $notice['date_end'],
                'multi_day' => $notice['date_end'] !== $notice['date'],
                'source_url' => $notice['source_url'],
                'source_name' => $notice['source_name'],
                'official' => $notice['source_name'] === 'Ministerstwo Energii',
                'day_links' => collect($notice['dates'])->map(fn ($date) => [
                    'date' => $date,
                    'url' => "/program-cpn/{$date}",
                ])->all(),
                ...$this->flatPrices($notice),
            ])->all(),
        ];
    }

    /**
     * Limit na dany dzień: oficjalny wpis max, a bez niego dzień z projekcji
     * (wzór z hurtu albo trend). Null, gdy nie ma ani jednego, ani drugiego.
     */
    public function nextLimit(string $date, Collection $max): ?array
    {
        if ($row = CpnData::find($date, $max)) {
            $dates = CpnData::noticeDates($row, $max);

            return [
                'date' => $date,
                'date_end' => end($dates),
                'kind' => 'official',
                'source_url' => $row['source_url'],
                'source_name' => $row['source_name'],
                'hurt_date' => null,
                'values' => collect(['pb95', 'pb98', 'on'])
                    ->filter(fn ($fuel) => $row[$fuel] !== null)
                    ->mapWithKeys(fn ($fuel) => [$fuel => ['price' => $row[$fuel], 'low' => $row[$fuel], 'high' => $row[$fuel]]])
                    ->all(),
            ];
        }

        $days = collect($this->projection()['days'] ?? []);
        $day = $days->firstWhere('date', $date);

        if (! $day) {
            return null;
        }

        $end = $date;

        if ($day['kind'] === 'formula') {
            $end = $days->where('kind', 'formula')->where('date', '>=', $date)->last()['date'];
        }

        return [
            'date' => $date,
            'date_end' => $end,
            'kind' => $day['kind'],
            'source_url' => null,
            'source_name' => null,
            'hurt_date' => $day['kind'] === 'formula' ? $this->hurtDateFor($date) : null,
            'values' => $day['values'],
        ];
    }

    private function daySummary(string $fuel, array $row, ?array $previous, Collection $earlier): string
    {
        $price = $row[$fuel];
        $label = self::FUELS[$fuel];
        $text = "{$label}: {$this->money($price)} zł/l";

        if (! $previous || $previous[$fuel] === null) {
            return "{$text} – pierwszy limit w danych tej edycji programu.";
        }

        $diff = round($price - $previous[$fuel], 2);
        $text .= match (true) {
            $diff < 0 => ', o '.$this->grosze($diff).' mniej niż w poprzednim dniu',
            $diff > 0 => ', o '.$this->grosze($diff).' więcej niż w poprzednim dniu',
            default => ', bez zmian względem poprzedniego dnia',
        };

        $values = $earlier->filter(fn ($item) => $item[$fuel] !== null);

        if ($diff < 0 && $values->every(fn ($item) => $item[$fuel] > $price)) {
            return "{$text} – najniższy limit od startu edycji.";
        }

        if ($diff > 0 && $values->every(fn ($item) => $item[$fuel] < $price)) {
            return "{$text} – najwyższy limit od startu edycji.";
        }

        if ($diff !== 0.0) {
            $since = $values->filter(fn ($item) => $diff < 0 ? $item[$fuel] <= $price : $item[$fuel] >= $price)->last();

            if ($since && $values->count() - $values->search($since) > 2) {
                return $text.' – '.($diff < 0 ? 'najniższy' : 'najwyższy').' od '.Carbon::parse($since['date'])->format('j.m').'.';
            }
        }

        return "{$text}.";
    }

    private function articlesAround(string $date): array
    {
        $from = Carbon::parse($date)->subDay()->toDateString();

        return Entry::query()
            ->where('collection', 'articles')
            ->whereStatus('published')
            ->whereTaxonomy('kategorie::program-cpn')
            ->orderBy('date', 'desc')
            ->get()
            ->filter(fn ($entry) => $entry->date()->toDateString() >= $from && $entry->date()->toDateString() <= $date)
            ->map(fn ($entry) => [
                'title' => $entry->get('title'),
                'url' => $entry->url(),
                'date' => $entry->date()->toDateString(),
            ])
            ->values()
            ->all();
    }

    private function fuelFaq(array $page, array $today, Collection $points, array $min, array $top): array
    {
        $fuel = $page['key'];
        $genitive = $page['genitive'];
        $date = Carbon::parse($today['date'])->format('j.m.Y');
        $start = Carbon::parse($points->first()['date'])->format('j.m.Y');
        $excise = $fuel === 'on' ? '880 zł za 1000 l oleju napędowego' : '1239 zł za 1000 l benzyny';

        $faq = [
            [
                'question' => "Ile wynosi dziś cena maksymalna {$genitive}?",
                'answer' => "Cena maksymalna {$genitive} na {$date} wynosi {$this->money($today[$fuel])} zł za litr brutto, według obwieszczenia Ministra Energii. Stacja nie może sprzedawać drożej, ale może taniej.",
            ],
            [
                'question' => "Jak obliczana jest cena maksymalna {$genitive}?",
                'answer' => "Limit to średnia hurtowa cena netto plus 0,30 zł na koszty stacji, pomnożone przez 1,08 (VAT 8%). Cena hurtowa zawiera już akcyzę i opłatę paliwową; w III edycji CPN akcyza wynosi {$excise}. Dlatego limit zmienia się razem z hurtem.",
            ],
            [
                'question' => "Jaka była najniższa i najwyższa cena maksymalna {$genitive} w CPN?",
                'answer' => "Od {$start} najniższy limit wyniósł {$this->money($min[$fuel])} zł/l (".Carbon::parse($min['date'])->format('j.m.Y')."), a najwyższy {$this->money($top[$fuel])} zł/l (".Carbon::parse($top['date'])->format('j.m.Y')."). Średnia z {$points->count()} dni to {$this->money($points->avg($fuel))} zł/l.",
            ],
        ];

        $faq[] = match ($fuel) {
            'on' => [
                'question' => 'Czy cena maksymalna oleju napędowego dotyczy też oleju zimowego?',
                'answer' => 'Tak. Limit dla oleju napędowego jest ogłaszany niezależnie od klasy sezonowej paliwa i obowiązuje w ramach III edycji CPN do 31 grudnia 2026 r.',
            ],
            'pb98' => [
                'question' => 'Czy benzyna 98 jest objęta ceną maksymalną?',
                'answer' => 'Tak. Program CPN wyznacza osobne ceny maksymalne dla benzyny 95, benzyny 98 i oleju napędowego. Limitem nie jest objęty autogaz (LPG).',
            ],
            default => [
                'question' => 'Czy stacja może sprzedawać benzynę 95 taniej niż cena maksymalna?',
                'answer' => 'Tak. Cena maksymalna to tylko górny pułap. Stacje mogą sprzedawać taniej, a dodatkowe rabaty dają promocje w aplikacjach sieci stacji.',
            ],
        };

        return $faq;
    }

    private function flatPrices(array $prices): array
    {
        return collect(['pb95', 'pb98', 'on'])
            ->mapWithKeys(fn ($fuel) => ["price_{$fuel}" => $this->money($prices[$fuel] ?? null)])
            ->all();
    }

    private function weekday(string $date): string
    {
        return Carbon::parse($date)->locale('pl')->isoFormat('dddd');
    }

    private function grosze(float $value): string
    {
        return number_format(abs($value) * 100, 0, ',', ' ').' gr';
    }

    private function predict(float $hurt): float
    {
        return round(($hurt + self::STATION_MARGIN) * self::VAT, 2);
    }

    /** Dni, dla których hurt z danego dnia wyznacza limit (piątek → sobota–poniedziałek). */
    private function limitDates(string $hurtDate): array
    {
        $day = Carbon::parse($hurtDate);
        $days = $day->isFriday() ? 3 : 1;

        return collect(range(1, $days))->map(fn ($i) => $day->copy()->addDays($i)->toDateString())->all();
    }

    /**
     * Limity na PROJECTION_DAYS dni po ostatnim oficjalnym. Dni wyznaczone przez znany
     * już hurt liczy wzór ('formula'), dalsze: kotwica + wygaszany trend ('trend').
     * Niepewność rośnie jak √(liczba notowań hurtu) — każde notowanie to nowy krok cen.
     */
    private function projection(): ?array
    {
        $max = $this->rows('max');
        // Sobotnie notowania dublują poniedziałkowe — do trendu tylko dni robocze.
        $hurt = $this->rows('hurt')->filter(fn ($row) => Carbon::parse($row['date'])->isWeekday())->values();

        if ($max->isEmpty() || $hurt->count() < 3) {
            return null;
        }

        $lastMax = $max->last();
        $latestHurt = $hurt->last();
        $formulaDates = array_values(array_filter(
            $this->limitDates($latestHurt['date']),
            fn ($date) => $date > $lastMax['date'],
        ));
        $anchorHurtDate = $formulaDates ? $latestHurt['date'] : $this->hurtDateFor($lastMax['date']);
        $window = $hurt->slice(-self::TREND_WINDOW)->values();

        $fuels = collect(['pb95', 'pb98', 'on'])
            ->filter(fn ($fuel) => $lastMax[$fuel] !== null && $window->every(fn ($row) => $row[$fuel] !== null))
            ->mapWithKeys(function ($fuel) use ($window, $formulaDates, $latestHurt, $lastMax) {
                $limits = $window->map(fn ($row) => $this->predict($row[$fuel]))->all();
                $slope = $this->slope($limits);

                return [$fuel => [
                    'anchor' => $formulaDates ? $this->predict($latestHurt[$fuel]) : $lastMax[$fuel],
                    'slope' => $slope,
                    'sigma' => $this->sigma($limits),
                    'weekly' => round($slope * 5, 2),
                    'direction' => abs($slope * 5) < self::TREND_FLAT ? 'flat' : ($slope > 0 ? 'up' : 'down'),
                ]];
            });

        if ($fuels->isEmpty()) {
            return null;
        }

        $endDate = Carbon::parse($lastMax['date'])->addDays(self::PROJECTION_DAYS)->toDateString();
        $totalSteps = $this->weekdaysBetween($anchorHurtDate, $this->hurtDateFor($endDate));
        $outlook = $this->outlook($lastMax['date']);

        // Świeża analiza rynku zastępuje trend z danych: cena na koniec okresu = limit z dnia analizy + oczekiwana zmiana.
        if ($outlook && $totalSteps > 0) {
            $base = $max->filter(fn ($row) => $row['date'] <= $outlook['date'])->last() ?? $lastMax;
            $fuels = $fuels->map(fn ($fuel, $key) => $outlook[$key] === null || $base[$key] === null ? $fuel : [
                ...$fuel,
                'target' => round($base[$key] + $outlook[$key], 2),
                'market' => $outlook[$key],
            ]);
        }

        $days = collect(range(1, self::PROJECTION_DAYS))->map(function ($offset) use ($lastMax, $formulaDates, $anchorHurtDate, $fuels, $totalSteps) {
            $date = Carbon::parse($lastMax['date'])->addDays($offset)->toDateString();
            $steps = $this->weekdaysBetween($anchorHurtDate, $this->hurtDateFor($date));
            $drift = array_sum(array_map(fn ($i) => self::TREND_DAMPING ** $i, $steps ? range(1, $steps) : []));

            return [
                'date' => $date,
                'kind' => in_array($date, $formulaDates, true) ? 'formula' : 'trend',
                'values' => $fuels->map(function ($fuel) use ($steps, $drift, $totalSteps) {
                    $price = round(isset($fuel['target'])
                        ? $fuel['anchor'] + ($fuel['target'] - $fuel['anchor']) * $steps / $totalSteps
                        : $fuel['anchor'] + $fuel['slope'] * $drift, 2);
                    $band = self::BAND_Z * $fuel['sigma'] * sqrt($steps);

                    return [
                        'price' => $price,
                        'low' => round($price - $band, 2),
                        'high' => round($price + $band, 2),
                    ];
                })->all(),
            ];
        })->all();

        return [
            'days' => $days,
            'fuels' => $fuels->all(),
            'outlook' => $fuels->contains(fn ($fuel) => isset($fuel['target'])) ? $outlook : null,
            'window' => $window->count(),
            'window_start' => $window->first()['date'],
        ];
    }

    /**
     * Najnowsza analiza rynku (wpis `outlook`), jeśli jest najwyżej OUTLOOK_MAX_AGE dni starsza
     * od ostatniego limitu. pb95/pb98/on = oczekiwana zmiana limitu w zł/l w ciągu 7 dni.
     */
    private function outlook(string $lastMaxDate): ?array
    {
        $entry = Entry::query()
            ->where('collection', 'cpn_prices')
            ->where('type', 'outlook')
            ->orderBy('date', 'desc')
            ->first();

        if (! $entry || $entry->date()->toDateString() < Carbon::parse($lastMaxDate)->subDays(self::OUTLOOK_MAX_AGE)->toDateString()) {
            return null;
        }

        return [
            'date' => $entry->date()->toDateString(),
            'pb95' => $this->float($entry->get('pb95')),
            'pb98' => $this->float($entry->get('pb98')),
            'on' => $this->float($entry->get('on')),
            'summary' => $entry->get('summary'),
            'factors' => collect($entry->get('factors') ?? [])->map(fn ($factor) => [
                'name' => $factor['name'] ?? null,
                'impact' => $factor['impact'] ?? 'neutral',
                'note' => $factor['note'] ?? null,
                'url' => $factor['url'] ?? null,
            ])->values()->all(),
            'source_name' => $entry->get('source_name'),
            'source_url' => $entry->get('source_url'),
        ];
    }

    /** Dzień notowania hurtu, z którego liczony jest limit na dany dzień (sob.–pon. → piątek). */
    private function hurtDateFor(string $limitDate): string
    {
        $day = Carbon::parse($limitDate)->subDay();

        while ($day->isWeekend()) {
            $day->subDay();
        }

        return $day->toDateString();
    }

    /** Liczba dni roboczych w przedziale (od, do]. */
    private function weekdaysBetween(string $from, string $to): int
    {
        $count = 0;

        for ($day = Carbon::parse($from)->addDay(); $day->toDateString() <= $to; $day->addDay()) {
            $count += $day->isWeekday() ? 1 : 0;
        }

        return $count;
    }

    /** Nachylenie prostej MNK (zł na notowanie). */
    private function slope(array $values): float
    {
        $n = count($values);
        $meanX = ($n - 1) / 2;
        $meanY = array_sum($values) / $n;
        $num = 0.0;
        $den = 0.0;

        foreach (array_values($values) as $x => $y) {
            $num += ($x - $meanX) * ($y - $meanY);
            $den += ($x - $meanX) ** 2;
        }

        return $den > 0 ? $num / $den : 0.0;
    }

    /** Odchylenie standardowe zmian z notowania na notowanie. */
    private function sigma(array $values): float
    {
        $values = array_values($values);
        $changes = [];

        for ($i = 1; $i < count($values); $i++) {
            $changes[] = $values[$i] - $values[$i - 1];
        }

        if (count($changes) < 2) {
            return 0.0;
        }

        $mean = array_sum($changes) / count($changes);

        return sqrt(array_sum(array_map(fn ($change) => ($change - $mean) ** 2, $changes)) / (count($changes) - 1));
    }

    /** Ile opublikowanych limitów wzór trafił co do grosza. */
    private function accuracy(Collection $max): array
    {
        $byDate = $max->keyBy('date');
        $checked = 0;
        $hits = 0;

        foreach ($this->rows('hurt') as $hurt) {
            foreach ($this->limitDates($hurt['date']) as $date) {
                foreach (['pb95', 'pb98', 'on'] as $fuel) {
                    $actual = $byDate[$date][$fuel] ?? null;

                    if ($hurt[$fuel] === null || $actual === null) {
                        continue;
                    }

                    $checked++;
                    $hits += abs($this->predict($hurt[$fuel]) - $actual) < 0.005 ? 1 : 0;
                }
            }
        }

        return ['checked' => $checked, 'hits' => $hits];
    }

    private function rows(string $type): Collection
    {
        return CpnData::rows($type);
    }

    /** Wiersze do dziś włącznie — limity opublikowane z wyprzedzeniem nie są jeszcze „dzisiejsze”. */
    private function untilToday(Collection $rows): Collection
    {
        $past = $rows->filter(fn ($row) => $row['date'] <= CpnData::today())->values();

        return $past->isEmpty() ? $rows : $past;
    }

    private function fuelsIn(Collection $rows): Collection
    {
        $only = $this->params->get('fuel');

        return collect(self::FUELS)
            ->filter(fn ($label, $fuel) => (! $only || $fuel === $only) && $rows->contains(fn ($row) => $row[$fuel] !== null));
    }

    private function delta(?float $current, ?float $reference): array
    {
        if ($current === null || $reference === null) {
            return ['trend' => null, 'change' => null, 'change_pct' => null];
        }

        $diff = round($current - $reference, 2);

        return [
            'trend' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'),
            'change' => ($diff > 0 ? '+' : ($diff < 0 ? '−' : '')).number_format(abs($diff) * 100, 0, ',', ' ').' gr',
            'change_pct' => ($diff > 0 ? '+' : ($diff < 0 ? '−' : '')).number_format(abs($diff / $reference * 100), 1, ',', ' ').'%',
        ];
    }

    private function signedGrosze(float $value): string
    {
        return ($value > 0 ? '+' : ($value < 0 ? '−' : '')).number_format(abs($value) * 100, 0, ',', ' ').' gr';
    }

    private function money(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 2, ',', ' ');
    }

    private function float($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
