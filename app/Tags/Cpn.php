<?php

namespace App\Tags;

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

        return json_encode([
            'dates' => $rows->pluck('date')->all(),
            'series' => $this->fuelsIn($rows)->map(fn ($label, $fuel) => [
                'key' => $fuel,
                'label' => $label,
                'values' => $rows->pluck($fuel)->all(),
            ])->values()->all(),
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

    private function money(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 2, ',', ' ');
    }

    private function float($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
