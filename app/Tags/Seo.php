<?php

namespace App\Tags;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Taxonomy;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Cascade;
use Statamic\Structures\Page;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Term as TermFacade;
use Statamic\Fields\Value;
use Statamic\Tags\Tags;
use Statamic\View\Antlers\Language\Runtime\StackReplacementManager;

class Seo extends Tags
{
    private const TIMEZONE = 'Europe/Warsaw';

    private const TITLE_LIMIT = 60;

    private const DESCRIPTION_LIMIT = 160;

    private const NOINDEX_PARAMS = ['stacja', 'paliwo', 'bez_aplikacji', 'sortuj'];

    private const TAXONOMY_INDEX_DESCRIPTIONS = [
        'paliwa' => 'Promocje na paliwo według rodzaju: benzyna 95 i 98, diesel, LPG i AdBlue. Aktualne rabaty, kupony i akcje na stacjach w Polsce.',
        'kategorie' => 'Kategorie bloga Radar Paliw: poradniki, ceny paliw, program CPN, aplikacje i programy lojalnościowe stacji.',
    ];

    public function meta(): array
    {
        $content = Cascade::instance()->content();

        if ($content instanceof Page && $content->entry()) {
            $content = $content->entry();
        }
        $brand = Site::current()->name();
        $isNotFound = (int) $this->context->value('response_code') === 404 || ! $content;
        $requestedPage = $this->pageNumber();
        $paginates = $this->isPaginated();
        $pageNumber = $paginates ? $requestedPage : 1;

        $title = $this->baseTitle($content, $brand, $isNotFound);
        $pagedTitle = $pageNumber > 1 ? "{$title} – strona {$pageNumber}" : $title;

        $description = $this->description($content, $isNotFound);

        if ($pageNumber > 1) {
            $suffix = " Strona {$pageNumber}.";
            $description = $this->clamp($description, self::DESCRIPTION_LIMIT - mb_strlen($suffix)).$suffix;
        }

        return [
            'meta_title' => $this->withBrand($pagedTitle, $brand),
            'meta_og_title' => $pagedTitle,
            'meta_description' => $this->clamp($description, self::DESCRIPTION_LIMIT),
            'meta_canonical' => $isNotFound ? null : $this->canonical($content, $pageNumber),
            'meta_robots' => $this->robots($content, $isNotFound, $requestedPage > 1 && ! $paginates),
            'meta_og_type' => $this->collectionHandle($content) === 'articles' ? 'article' : 'website',
            'meta_is_404' => $isNotFound,
            'meta_page' => $pageNumber,
            'meta_published' => $content instanceof Entry && $content->hasDate() ? $this->iso($content->date()) : null,
            'meta_modified' => $content && method_exists($content, 'lastModified') ? $this->iso($content->lastModified()) : null,
            'meta_section' => $this->articleSection($content),
            'meta_author' => $this->articleAuthor($content),
            'meta_has_itemlist' => $this->hasItemList(),
        ];
    }

    private function pageNumber(): int
    {
        $page = request()->query('page');

        return is_scalar($page) && ctype_digit((string) $page) && (int) $page > 1 ? (int) $page : 1;
    }

    private function baseTitle($content, string $brand, bool $isNotFound): string
    {
        $section = $this->plain(Cascade::instance()->sections()->get('seo_title'));

        if ($section !== '') {
            return $this->stripBrand($section, $brand);
        }

        if ($isNotFound) {
            return 'Nie znaleziono strony (404)';
        }

        $title = $this->plain($this->field($content, 'seo_title')) ?: $this->plain($this->field($content, 'title'));

        return $this->stripBrand($title, $brand) ?: $brand;
    }

    private function stripBrand(string $title, string $brand): string
    {
        return trim(preg_replace('/\s*[·|–—-]\s*'.preg_quote($brand, '/').'\s*$/u', '', $title));
    }

    private function withBrand(string $title, string $brand): string
    {
        if (Str::contains($title, $brand)) {
            return $title;
        }

        $branded = "{$title} | {$brand}";

        return mb_strlen($branded) <= self::TITLE_LIMIT ? $branded : $title;
    }

    private function description($content, bool $isNotFound): string
    {
        $candidates = [
            Cascade::instance()->sections()->get('seo_description'),
            $isNotFound ? 'Nie znaleźliśmy tej strony. Sprawdź aktualne promocje na paliwo, ceny maksymalne w programie CPN i poradniki na blogu Radar Paliw.' : null,
            $this->field($content, 'seo_description'),
            $this->field($content, 'excerpt'),
            $this->field($content, 'description'),
            $this->field($content, 'bio'),
            $this->generatedDescription($content),
            GlobalSet::findByHandle('settings')?->inCurrentSite()?->get('seo_description'),
        ];

        foreach ($candidates as $candidate) {
            if (($text = $this->plain($candidate)) !== '') {
                return $text;
            }
        }

        return '';
    }

    private function generatedDescription($content): ?string
    {
        if ($content instanceof Taxonomy) {
            return self::TAXONOMY_INDEX_DESCRIPTIONS[$content->handle()] ?? null;
        }

        $title = $this->plain($this->field($content, 'title'));

        if ($content instanceof Term) {
            return match ($content->taxonomyHandle()) {
                'paliwa' => "Aktualne promocje na {$title} na stacjach w Polsce: rabaty, kupony i akcje w aplikacjach. Sprawdź, gdzie dziś zatankujesz taniej.",
                'kategorie' => "Artykuły z kategorii {$title} w Radar Paliw: poradniki i analizy o paliwach, cenach i promocjach na stacjach.",
                default => null,
            };
        }

        return match ($this->collectionHandle($content)) {
            'promotions' => $this->promotionDescription($content, $title),
            'stations' => "Aktualne promocje na paliwo na stacjach {$title}: rabaty, kupony i akcje w aplikacji. Sprawdź warunki i daty obowiązywania.",
            'authors' => "Artykuły i poradniki autora {$title} w Radar Paliw.",
            default => null,
        };
    }

    private function promotionDescription(Entry $entry, string $title): string
    {
        $station = $this->relatedEntry($entry, 'station');
        $prefix = $station ? $this->plain($station->get('title')).': ' : '';
        $validTo = $entry->get('valid_to');
        $until = $validTo ? ' do '.Carbon::parse($validTo)->format('j.m.Y') : '';

        return "{$prefix}{$title} – rabat na paliwo{$until}. Sprawdź warunki promocji.";
    }

    private function canonical($content, int $pageNumber): string
    {
        $custom = trim((string) $this->plain($this->field($content, 'canonical_url')));

        if ($custom !== '') {
            return Str::startsWith($custom, '/') ? url($custom) : $custom;
        }

        $url = $this->context->value('is_homepage')
            ? rtrim(Site::current()->absoluteUrl(), '/').'/'
            : rtrim((string) $this->context->value('current_url'), '/');

        return $pageNumber > 1 ? "{$url}?page={$pageNumber}" : $url;
    }

    private function robots($content, bool $isNotFound, bool $strayPage = false): string
    {
        $filtered = collect(array_keys(request()->query()))
            ->contains(fn ($key) => in_array($key, self::NOINDEX_PARAMS, true) || Str::startsWith($key, 'utm_'));

        $emptyTerm = $content instanceof Term && $content->queryEntries()->count() === 0;

        if ($isNotFound || $filtered || $strayPage || $emptyTerm || $this->field($content, 'noindex')?->value() || ! app()->environment('production')) {
            return 'noindex, follow';
        }

        return 'index, follow, max-image-preview:large';
    }

    private function isPaginated(): bool
    {
        return $this->plain(Cascade::instance()->sections()->get('paginated')) === '1' || $this->hasItemList();
    }

    private function hasItemList(): bool
    {
        $current = rtrim((string) $this->context->value('current_url'), '/');

        return collect(StackReplacementManager::getStacks()['schema'] ?? [])
            ->contains(fn ($node) => Str::contains($node, "\"{$current}#itemlist\""));
    }

    private function articleSection($content): ?string
    {
        if ($this->collectionHandle($content) !== 'articles') {
            return null;
        }

        $slug = collect($content->get('kategorie'))->first();
        $term = $slug ? TermFacade::find("kategorie::{$slug}") : null;

        return $term ? $this->plain($term->title()) : null;
    }

    private function articleAuthor($content): ?string
    {
        if ($this->collectionHandle($content) !== 'articles') {
            return null;
        }

        return $this->relatedEntry($content, 'author')?->absoluteUrl();
    }

    private function relatedEntry(Entry $entry, string $field): ?Entry
    {
        $value = $entry->augmentedValue($field)->value();

        if (is_iterable($value)) {
            $value = collect($value)->first();
        } elseif (is_object($value) && method_exists($value, 'first')) {
            $value = $value->first();
        }

        return $value instanceof Entry ? $value : null;
    }

    private function collectionHandle($content): ?string
    {
        return $content instanceof Entry ? $content->collectionHandle() : null;
    }

    private function field($content, string $handle)
    {
        if ($content && method_exists($content, 'augmentedValue')) {
            return $content->augmentedValue($handle);
        }

        return $content instanceof Taxonomy && $handle === 'title' ? $content->title() : null;
    }

    private function plain($value): string
    {
        if ($value instanceof Value) {
            $value = $value->fieldtype()?->handle() === 'bard' ? $value->fieldtype()->preProcessIndex($value->raw()) : $value->value();
        }

        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return '';
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function clamp(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit - 1);
        $cut = preg_replace('/\s+\S*$/u', '', $cut) ?: $cut;

        return rtrim($cut, " ,;:–—-").'…';
    }

    private function iso($date): ?string
    {
        return $date instanceof CarbonInterface ? $date->copy()->setTimezone(self::TIMEZONE)->toAtomString() : null;
    }
}
