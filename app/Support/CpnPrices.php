<?php

namespace App\Support;

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
     * względem poprzedniego wpisu.
     *
     * @return array{date: string, fuels: array<string, array{label: string, price: float, diff: ?float}>}|null
     */
    public static function latest(): ?array
    {
        $entries = Entry::query()
            ->where('collection', 'cpn_prices')
            ->where('type', 'max')
            ->orderBy('date', 'desc')
            ->limit(2)
            ->get();

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
