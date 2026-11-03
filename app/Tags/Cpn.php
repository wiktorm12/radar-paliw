<?php

namespace App\Tags;

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

        $latest = $max->last();

        return [
            'date' => $dates[0],
            'date_end' => end($dates),
            'hurt_date' => $hurt['date'],
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

        $latest = $this->rows('max')->last();
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
        $current = $rows->last();

        if (! $current) {
            return $this->parseNoResults();
        }

        $previous = $rows->count() > 1 ? $rows->get($rows->count() - 2) : null;

        return [
            'date' => $current['date'],
            'source_url' => $current['source_url'],
            'source_name' => $current['source_name'],
            'fuels' => $this->fuelsIn($rows)->map(fn ($label, $fuel) => [
                'key' => $fuel,
                'label' => $label,
                'price' => $this->money($current[$fuel]),
                ...$this->delta($current[$fuel], $previous[$fuel] ?? null),
            ])->filter(fn ($fuel) => $fuel['price'] !== null)->values()->all(),
        ];
    }

    public function stats()
    {
        $rows = $this->rows($this->params->get('type', 'max'));

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
                'series' => collect($projection['fuels'])->map(fn ($fuel, $key) => [
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
        $max = $this->rows('max')->last();
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
        return Entry::query()
            ->where('collection', 'cpn_prices')
            ->where('type', $type)
            ->orderBy('date', 'asc')
            ->get()
            ->map(fn ($entry) => [
                'date' => $entry->date()->toDateString(),
                'pb95' => $this->float($entry->get('pb95')),
                'pb98' => $this->float($entry->get('pb98')),
                'on' => $this->float($entry->get('on')),
                'lpg' => $this->float($entry->get('lpg')),
                'source_name' => $entry->get('source_name'),
                'source_url' => $entry->get('source_url'),
            ])
            ->values();
    }

    private function fuelsIn(Collection $rows): Collection
    {
        return collect(self::FUELS)->filter(fn ($label, $fuel) => $rows->contains(fn ($row) => $row[$fuel] !== null));
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
