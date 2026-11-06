<?php

namespace App\Support;

use App\Cpn\CpnData;
use Statamic\Facades\Entry;

class CpnPrices
{
    public const FUELS = [
        'pb95' => 'Benzyna 95',
        'pb98' => 'Benzyna 98',
        'on' => 'Olej napędowy',
    ];

    /**
     * Najnowsza cena maksymalna (dzisiejsza albo już opublikowana jutrzejsza) wraz z różnicą
     * względem poprzedniego wpisu. Z $untilToday — limit obowiązujący dziś (ministerstwo
     * publikuje czasem limity na kilka dni naprzód).
     *
     * @return array{date: string, fuels: array<string, array{label: string, price: float, diff: ?float}>}|null
     */
    public static function latest(bool $untilToday = false): ?array
    {
        $entries = Entry::query()
            ->where('collection', 'cpn_prices')
            ->where('type', 'max')
            ->orderBy('date', 'desc')
            ->get()
            ->when($untilToday, fn ($entries) => $entries->filter(fn ($entry) => $entry->date()->toDateString() <= CpnData::today()))
            ->take(2)
            ->values();

        $current = $entries->first();

        if (! $current) {
            return null;
        }

        $previous = $entries->get(1);
        $fuels = [];

        foreach (self::FUELS as $key => $label) {
            if (! is_numeric($current->get($key))) {
                continue;
            }

            $price = (float) $current->get($key);

            $fuels[$key] = [
                'label' => $label,
                'price' => $price,
                'diff' => $previous && is_numeric($previous->get($key)) ? round($price - (float) $previous->get($key), 2) : null,
            ];
        }

        return $fuels === [] ? null : ['date' => $current->date()->toDateString(), 'fuels' => $fuels];
    }

    public static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    public static function grosze(?float $diff): ?string
    {
        if ($diff === null) {
            return null;
        }

        return ($diff > 0 ? '+' : ($diff < 0 ? '−' : '')).number_format(abs($diff) * 100, 0, ',', ' ').' gr';
    }
}
