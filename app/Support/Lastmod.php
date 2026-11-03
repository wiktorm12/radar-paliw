<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * Data ostatniej zmiany treści (sitemapy, dateModified, article:modified_time).
 *
 * mtime plików nic nie znaczy — każdy deploy tworzy je od nowa — więc źródłem jest (w kolejności):
 * `updated_at` z front-matter (edycje w CP na produkcji) > manifest `content/.lastmod`
 * (utrzymywany hookiem pre-commit, `content:lastmod`) > data wpisu (dla datowanych) > `lastModified()`.
 */
class Lastmod
{
    public const TIMEZONE = 'Europe/Warsaw';

    public const CPN_PAGE_ID = 'page-program-cpn';

    public const MANIFEST = 'content/.lastmod';

    private static ?Carbon $cpn = null;

    private static bool $cpnResolved = false;

    /** @var array<string, string>|null ścieżka względem katalogu projektu => ISO 8601 */
    private static ?array $manifest = null;

    /** Wpis lub term. */
    public static function of($content): Carbon
    {
        if ($content instanceof EntryContract && $content->id() === self::CPN_PAGE_ID && ($cpn = self::cpn())) {
            return $cpn;
        }

        if ($explicit = self::explicit($content)) {
            return $explicit;
        }

        if ($content instanceof EntryContract && $content->hasDate()) {
            return self::entryDate($content);
        }

        return self::warsaw($content->lastModified());
    }

    /**
     * Data wpisu cpn_prices (gdy już nastała) albo jawna data zmiany — późniejsza z nich.
     * Wspólna dla Lastmod::cpn() i CpnData, więc lastmod huba i seo_modified są spójne.
     */
    public static function cpnEntry(EntryContract $entry): Carbon
    {
        $now = Carbon::now();
        $date = $entry->hasDate() ? self::entryDate($entry) : null;
        $date = $date && $date->lte($now) ? $date : null;
        $explicit = self::explicit($entry);

        $latest = collect([$date, $explicit])->filter()->max();

        return self::warsaw($latest ?? $entry->lastModified());
    }

    /** Najnowszy wpis "max" lub "outlook" z cpn_prices. */
    public static function cpn(): ?Carbon
    {
        if (self::$cpnResolved) {
            return self::$cpn;
        }

        $latest = Entry::query()
            ->where('collection', 'cpn_prices')
            ->whereIn('type', ['max', 'outlook'])
            ->whereStatus('published')
            ->get()
            ->map(fn ($entry) => self::cpnEntry($entry))
            ->max();

        self::$cpn = $latest ? self::warsaw($latest) : null;
        self::$cpnResolved = true;

        return self::$cpn;
    }

    /** `updated_at` z front-matter, potem wpis z manifestu; null, gdy żadnego nie ma. */
    public static function explicit($content): ?Carbon
    {
        if ($updated = $content->get('updated_at')) {
            return self::warsaw(Carbon::createFromTimestamp((int) $updated));
        }

        $key = self::relativePath($content);
        $iso = $key !== null ? (self::manifest()[$key] ?? null) : null;

        if ($iso === null) {
            return null;
        }

        try {
            return self::warsaw(Carbon::parse($iso));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Manifest wczytywany raz na request. */
    public static function manifest(): array
    {
        return self::$manifest ??= self::readManifest(base_path(self::MANIFEST));
    }

    public static function readManifest(string $file): array
    {
        $text = @file_get_contents($file);

        return $text === false ? [] : self::parseManifest($text);
    }

    /** Linie `ścieżka<TAB>ISO8601`; pomija puste i uszkodzone, przy duplikatach bierze najpóźniejszą datę. */
    public static function parseManifest(string $text): array
    {
        $map = [];

        foreach (preg_split('/\R/', $text) as $line) {
            $parts = explode("\t", trim($line, "\r\n"));

            if (count($parts) !== 2 || $parts[0] === '' || ($time = strtotime($parts[1])) === false) {
                continue;
            }

            if (! isset($map[$parts[0]]) || $time > strtotime($map[$parts[0]])) {
                $map[$parts[0]] = $parts[1];
            }
        }

        return $map;
    }

    /** Posortowane po ścieżce, jedna linia na plik. */
    public static function formatManifest(array $map): string
    {
        ksort($map, SORT_STRING);

        return implode('', array_map(fn ($path, $iso) => "{$path}\t{$iso}\n", array_keys($map), $map));
    }

    public static function setManifest(?array $manifest): void
    {
        self::$manifest = $manifest;
    }

    public static function forget(): void
    {
        self::$cpn = null;
        self::$cpnResolved = false;
        self::$manifest = null;
    }

    public static function relativePath($content): ?string
    {
        try {
            $path = method_exists($content, 'path') ? $content->path() : null;
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($path) || $path === '') {
            return null;
        }

        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : null;
    }

    public static function warsaw($date): Carbon
    {
        return Carbon::instance($date)->setTimezone(self::TIMEZONE);
    }

    /** Entry date as an instant; a date without time means the start of that day in Warsaw. */
    public static function entryDate(EntryContract $entry): Carbon
    {
        $date = $entry->date();

        if ($entry->hasTime()) {
            return Carbon::instance($date);
        }

        return Carbon::parse($date->format('Y-m-d'), self::TIMEZONE)->startOfDay();
    }
}
