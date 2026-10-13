# Radar Paliw

Agregator promocji na paliwo największych polskich sieci stacji. Statamic 6 (flat-file) ·
Laravel · PHP 8.3 · Tailwind CSS 4 · Vite · Antlers. Konwencje frontendu: skill
`statamic-cms-skill` — obowiązuje w całości.

## Wartości projektowe

- Kontener assetów: `img` (dysk `img` → `public/img`, URL `/img`)
- Site: `default`, locale `pl_PL`, strefa czasowa `Europe/Warsaw`
- Lokalnie: Laravel Herd (`radarpaliw.test`)
- Paleta: `brand-*` w `@theme` (`resources/css/site.css`); font: systemowy stos sans
- Vendor CSS: brak; pluginy Tailwind: `@tailwindcss/forms`, `@tailwindcss/typography`
- Integracje: brak (SEO addon jeszcze nie wybrany — layout ma własny `<title>`)

## Model treści

| Element | Handle | URL |
|---|---|---|
| Strony (page builder) | `pages` | `/{slug}` — archiwa `/promocje`, `/stacje`, `/blog` to zwykłe strony |
| Sieci stacji | `stations` | `/stacje/{slug}` |
| Promocje | `promotions` | `/promocje/{slug}` |
| Artykuły (blog, `date: true`) | `articles` | `/blog/{slug}` |
| Autorzy (byline, nie konta CP) | `authors` | `/autorzy/{slug}` |
| Rodzaje paliwa (taksonomia) | `paliwa` | `/paliwa/{slug}` |
| Kategorie bloga (taksonomia) | `kategorie` | `/kategorie/{slug}` |
| Ustawienia / Reklamy (globale) | `settings`, `ads` | — |
| Nawigacje | `main`, `footer` | — |

Handle taksonomii są po polsku celowo — Statamic buduje z nich URL termów.

Query scopes (`app/Scopes`): `active_promotions` (`valid_to >= dziś`, parametry `stations`,
`fuel_type`, `exclude`), `articles_filter` (`category`, `station`, `author`, `exclude`). Listy renderują
`partials/components/{promotions,articles}/_list` — używaj ich zamiast nowych pętli.

## Bloki buildera

`block_hero`, `block_text`, `block_promotions_list`, `block_ad`, `block_articles_list`,
`block_stations_grid`, `block_faq`.

## Reklamy

- Kreacje: global `ads` → replicator `slots` (kod sieci albo baner), pole `placement`
  z fieldsetu `partial_ad_placement`. Kilka kreacji na jedno miejsce = losowa rotacja.
- Render: `{{ partial:ads/slot position="…" }}` → tag `ad_slot` (`app/Tags/AdSlot.php`).
  Rezerwuje wysokość (mapa `formats` we front-matter partiala) — nowe miejsce dopisz
  w obu: `partial_ad_placement.yaml` i mapie `formats`.
- Poza produkcją pusty slot pokazuje przerywany placeholder z nazwą miejsca.
- `head_code` ładowany tylko na produkcji; `/ads.txt` serwowany z globala.

## SEO

- `partials/layout/_head`: title/description/canonical/robots/OG. Szablon może nadpisać
  przez `{{ section:seo_title }}` / `{{ section:seo_description }}`. Poza produkcją robots
  = `noindex`.
- Fieldset `partial_seo` w zakładce SEO każdego blueprintu (strony, stacje, promocje,
  artykuły, termy).
- JSON-LD: jeden `@graph` w `partials/meta-tags/_schema` (Organization, WebSite, WebPage).
  Szablon ustawia `{{ section:schema_page_type }}`, `{{ section:schema_main_entity }}`
  i dokłada węzły przez `{{ push:schema }}` — każdy węzeł zaczyna się od przecinka.
  Breadcrumbs (`_breadcrumbs`) mają osobny BreadcrumbList z `@id …#breadcrumb`.
- W Antlers `a ?? b | modyfikator` stosuje modyfikator tylko do `b` — zawsze nawiasy:
  `(a ?? b) | to_json`.
- Sitemapy: `/sitemap.xml` (index) + `/sitemap-{kolekcja|taksonomia}.xml` (`routes/web.php`,
  widoki `resources/views/sitemaps`). `/robots.txt` z trasy — Herd/Forge nginx ma
  `location = /robots.txt` bez fallbacku do PHP, trzeba go usunąć albo dodać `try_files`.
