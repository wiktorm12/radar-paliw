<?php

namespace App\Cpn;

use App\Support\Lastmod;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Statamic\Facades\Entry;

class CpnData
{
    public const TIMEZONE = 'Europe/Warsaw';

    public const HUB_ID = 'page-program-cpn';

    public const EDITION_START = '2026-10-03';

    public const LICENSE_URL = 'https://creativecommons.org/licenses/by/4.0/';

    public const FUEL_PAGES = [
        'benzyna-95' => ['key' => 'pb95', 'term' => 'pb95', 'label' => 'Benzyna 95', 'short' => 'Pb95', 'genitive' => 'benzyny 95'],
        'benzyna-98' => ['key' => 'pb98', 'term' => 'pb98', 'label' => 'Benzyna 98', 'short' => 'Pb98', 'genitive' => 'benzyny 98'],
        'olej-napedowy' => ['key' => 'on', 'term' => 'on', 'label' => 'Olej napędowy', 'short' => 'ON', 'genitive' => 'oleju napędowego'],
    ];

    public static function rows(string $type): Collection
    {
        return Entry::query()
            ->where('collection', 'cpn_prices')
            ->where('type', $type)
            ->whereStatus('published')
            ->orderBy('date', 'asc')
            ->get()
            ->map(fn ($entry) => [
                'date' => $entry->date()->toDateString(),
                'pb95' => self::float($entry->get('pb95')),
                'pb98' => self::float($entry->get('pb98')),
                'on' => self::float($entry->get('on')),
                'lpg' => self::float($entry->get('lpg')),
                'source_name' => $entry->get('source_name'),
                'source_url' => $entry->get('source_url'),
                'modified' => self::entryModified($entry),
            ])
            ->values();
    }

    public static function today(): string
    {
        return Carbon::now(self::TIMEZONE)->toDateString();
    }

    public static function tomorrow(): string
    {
        return Carbon::now(self::TIMEZONE)->addDay()->toDateString();
    }

    /** Limit obowiązujący dziś: ostatni wpis max z datą nie późniejszą niż dzisiejsza. */
    public static function current(?Collection $max = null): ?array
    {
        $max ??= self::rows('max');

        return $max->filter(fn ($row) => $row['date'] <= self::today())->last() ?? $max->first();
    }

    public static function find(string $date, ?Collection $max = null): ?array
    {
        return ($max ?? self::rows('max'))->firstWhere('date', $date);
    }

    /** Daty obowiązywania limitu z tego samego obwieszczenia (wspólny source_url), np. sobota–poniedziałek. */
    public static function noticeDates(array $row, Collection $max): array
    {
        if (! $row['source_url']) {
            return [$row['date']];
        }

        return $max->where('source_url', $row['source_url'])->pluck('date')->values()->all();
    }

    public static function notices(?Collection $max = null): Collection
    {
        return ($max ?? self::rows('max'))
            ->groupBy(fn ($row) => $row['source_url'] ?: $row['date'])
            ->map(fn ($rows) => [
                ...$rows->first(),
                'date_end' => $rows->last()['date'],
                'dates' => $rows->pluck('date')->all(),
                'modified' => $rows->max('modified'),
            ])
            ->sortByDesc('date')
            ->values();
    }

    /** Ostatnia zmiana danych strony — dla seo_modified i lastmod w sitemapie. */
    public static function modified(string $page, ?string $date = null): ?string
    {
        $latest = fn (string $type) => self::rows($type)->max('modified');

        $values = match ($page) {
            'day' => [self::find((string) $date)['modified'] ?? null],
            'notices' => [$latest('max')],
            'tomorrow' => [$latest('max'), $latest('hurt'), $latest('outlook')],
            default => [$latest('max'), $latest('outlook')],
        };

        $timestamp = collect($values)->filter()->max();

        return $timestamp ? self::iso(Carbon::createFromTimestamp($timestamp)) : null;
    }

    /** Data wpisu (gdy już nastała) albo jawna data zmiany — wspólna reguła z App\Support\Lastmod::cpn(). */
    private static function entryModified($entry): int
    {
        return Lastmod::cpnEntry($entry)->getTimestamp();
    }

    public static function iso(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone(self::TIMEZONE)->toAtomString();
    }

    private static function float($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
