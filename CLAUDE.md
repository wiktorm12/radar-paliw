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
| Oferty partnerów (afiliacja) | `offers` | — (bez URL) |
| Rodzaje paliwa (taksonomia) | `paliwa` | `/paliwa/{slug}` |
| Kategorie bloga (taksonomia) | `kategorie` | `/kategorie/{slug}` |
| Ustawienia / Reklamy (globale) | `settings`, `ads` | — |
| Nawigacje | `main`, `footer` | — |

Handle taksonomii są po polsku celowo — Statamic buduje z nich URL termów.

Query scopes (`app/Scopes`): `active_promotions` (`status` active|expired względem dziś w Europe/Warsaw,
parametry `stations`, `station_slug`, `fuel_type`, `no_app`, `exclude`), `articles_filter` (`category`, `station`, `author`, `exclude`). Listy renderują
`partials/components/{promotions,articles}/_list` — używaj ich zamiast nowych pętli.

## Promocje: oznaczenia, filtry, archiwum

- Wartości wyliczane (`AppServiceProvider`): `badge` (`new`, `ending_today`, `ending_soon`, `upcoming`,
  `expired`) i `discount_rank` (do sortowania, null = -1). Etykiety/kolory: front-matter `_card`.
- `block_promotions_list`: `status` (active/expired), `paginate`, `show_filters`. Filtry to GET
  (`stacja`, `paliwo`, `bez_aplikacji`, `sortuj`), działają bez JS; `modules/promotion-filters.js`
  wysyła formularz przy zmianie. Canonical stron z filtrami wskazuje na URL bez parametrów.
- Archiwum: strona `/promocje/archiwum` (dziecko `/promocje` w drzewie stron).

## Program CPN

- Strona `/program-cpn` (wpis `pages` z `template: templates/cpn`): kafelki z dzisiejszą ceną
  maksymalną, wykres (`modules/price-chart.js`, dane w `<script type="application/json"
  data-price-chart-data>`), `#tankowac-dzis-czy-poczekac`, statystyki, porównanie z ceną sprzed programu,
  tabela (daty linkują do stron dni), `#po-koncu-cpn` (VAT 23%), `#kalkulator`, `#dane` (eksport i widget),
  a pod spodem zwykły builder (`content`) z opisem i FAQ (14 pytań → FAQPage).
- Dane: kolekcja `cpn_prices` (dated, bez route), `type` = `max` (dzienne ceny maksymalne ME) albo
  `retail` (średnie e-petrol) albo `hurt` (ceny hurtowe Orlen netto w zł/l, data = dzień obowiązywania
  hurtu). Jeden wpis na dzień obowiązywania limitu.
- Prognoza (`{{ cpn:forecast }}`, blok `#prognoza`): kolejny limit = round((hurt netto + 0,30) × 1,08, 2),
  hurt z dnia D → limit na D+1 (piątek → sob.–pon.). Ukrywa się, gdy oficjalny limit już jest.
- Trend i prognoza 7 dni (`{{ cpn:trend }}`, blok `#trend`, na wykresie przez `cpn:series projection="true"`):
  dni ze znanego hurtu = wzór; dalej, gdy jest świeży wpis `type: outlook` (analiza rynku, max 2 dni starszy
  od ostatniego limitu), liniowo do limitu z dnia analizy + oczekiwana zmiana; bez niego wygaszany
  (×0,8/notowanie) trend MNK z 10 ostatnich roboczych notowań hurtu. Zakres 80% = 1,28·σ(zmian)·√(liczba notowań).
- Wpis `outlook`: `pb95`/`pb98`/`on` = oczekiwana zmiana limitu w zł/l w 7 dni, `summary`, `factors`
  (`name`, `impact` up|down|neutral, `note`, `url`), `source_name`/`source_url`.
- Tag `{{ cpn:* }}` (`app/Tags/Cpn.php`, dane z `App\Cpn\CpnData`): `latest`, `forecast`, `trend`, `stats`, `table`,
  `columns`, `series` (JSON), `compare`, `meta` oraz `verdict` (tankować dziś czy poczekać), `vat` (limit przy VAT 23%
  i z pełną akcyzą), `tomorrow`, `day date=…`, `fuel slug=…`, `notices`, `modified page=…`, `latest_date`.
  `stats`/`table`/`columns`/`series` przyjmują `fuel="pb95|pb98|on"` (jedna seria). Klucze w pętlach nie mogą
  nazywać się `first`/`last`/`count`/`next`, ani powtarzać klucza z zewnętrznego zakresu (np. `days` w pętli
  pod `days`) — kolidują ze zmiennymi Antlers i potrafią wyciąć cały blok.
- Podstrony (`App\Http\Controllers\CpnController`, szablony `templates/cpn/*`, partiale `components/cpn/*`):
  `/program-cpn/jutro` (oficjalny limit na jutro albo prognoza), `/program-cpn/{benzyna-95|benzyna-98|olej-napedowy}`,
  `/program-cpn/{RRRR-MM-DD}` (tylko daty z wpisem `max`, inne → 404), `/program-cpn/obwieszczenia` (wpisy `max`
  pogrupowane po `source_url`). To trasy, nie wpisy `pages`: kontroler renderuje szablon z layoutem, a kaskadę
  ładuje wpis huba (`page-program-cpn`), więc działają `seo:meta` i okruszki. Ostatni okruszek podaje zmienna
  `breadcrumb_title` (obsługa w `layout/_breadcrumbs`). Linki między podstronami: `components/cpn/subnav`.
- Eksport: `/program-cpn/dane.csv` i `/program-cpn/dane.json` (wszystkie wpisy `max`, licencja CC BY 4.0 z linkiem
  do źródła). Widget do osadzania: `/program-cpn/widget` (bez layoutu, noindex, `frame-ancestors *`), kod iframe
  w sekcji `#dane` huba. Kalkulator tankowania: `modules/cpn-calculator.js` (bez JS widać tabelę dla 50 l).
- `/sitemap-cpn.xml` (`CpnController@sitemap`, widok `sitemaps/cpn.blade.php`): jutro, paliwa, obwieszczenia i dni;
  hub jest w `sitemap-pages.xml`. lastmod = `CpnData::modified()`.
- Kontrakty sekcji: każdy szablon CPN ustawia `{{ section:seo_modified }}` (`cpn:modified` — data wpisu, gdy już
  nastała, albo czas pliku; hub: najnowszy `max`/`outlook`, ta sama reguła co `App\Support\Lastmod::cpn()` i `Lastmod::cpnEntry()`),
  `{{ section:og_image_url }}` (`/og/program-cpn.png?v={data dzisiejszego limitu}`, obraz z dzisiejszym limitem — `CpnPrices::latest(untilToday: true)`) oraz `seo_title`/`seo_description`
  budowane z danych (hub nie ma `seo_title` we wpisie).
- Strona `/dane-cpn` (`CpnController::docs`, `templates/cpn/docs`): dokumentacja widgetu, API JSON, CSV, licencji i kontakt dla mediów;
  przycisk „Kopiuj” to `modules/copy-button.js` (`components/cpn/code-block`). `/program-cpn/dane.json` ma pole `version` (1) —
  zmiany muszą być wstecznie zgodne: nowa wersja = nowe pola, nigdy zmiana ani usunięcie istniejących (`today`, `tomorrow` korzystają z `CpnData::current()` i `Cpn::nextLimit()`).
- Kategoria bloga `program-cpn` (25 artykułów). Codzienne zadanie uzupełnia ceny CPN.
- Kolory serii wykresu (paleta zwalidowana dla CVD): Pb95 `#2a78d6`, Pb98 `#eb6834`,
  ON `#1baf7a`, LPG `#eda100`.

## RSS

`/promocje/feed.xml` i `/blog/feed.xml` (`resources/views/feeds`, `content_type: rss`). W XML używaj
`| sanitize`, nie `| entities` (encje HTML psują XML).

## Strony prawne

`/o-nas`, `/kontakt`, `/regulamin`, `/polityka-prywatnosci` — wpisy w `pages`, w menu `footer`.
Wydawca to „redakcja serwisu internetowego Radar Paliw (radarpaliw.pl)”, kontakt `kontakt@radarpaliw.pl` — bez danych osobowych.

## Bloki buildera

`block_hero`, `block_text`, `block_promotions_list`, `block_ad`, `block_articles_list`,
`block_stations_grid`, `block_faq`, `block_offers`, `block_cpn_prices` (kafel z dzisiejszymi cenami CPN
z `{{ cpn:latest }}` + linki do podstron `/program-cpn/*`; na stronie głównej po hero).

## Reklamy

- Kreacje: global `ads` → replicator `slots` (kod sieci albo baner), pole `placement`
  z fieldsetu `partial_ad_placement`. Kilka kreacji na jedno miejsce = losowa rotacja.
- Render: `{{ partial:ads/slot position="…" }}` → tag `ad_slot` (`app/Tags/AdSlot.php`).
  Rezerwuje wysokość (mapa `formats` we front-matter partiala) — nowe miejsce dopisz
  w obu: `partial_ad_placement.yaml` i mapie `formats`.
- Poza produkcją pusty slot pokazuje przerywany placeholder z nazwą miejsca.
- Puste sloty AdSense (`data-ad-status="unfilled"`) chowa CSS w `components/ads.css` przez `:has()`; nowy wrapper wokół slotu oznacz `data-ad-wrapper`.
- `head_code` ładowany tylko na produkcji; `/ads.txt` serwowany z globala.
- Zewnętrzne skrypty reklam (`head_code`, kod slotów, Convertiser) przechodzą przez modyfikator
  `defer_scripts` → `<script type="text/plain" data-deferred-src>`; ładuje je `modules/deferred-scripts.js`
  po pierwszej interakcji (albo 8 s po `load`). Nowy kod sieci reklamowej renderuj z `| defer_scripts`.
- Na produkcji CSS jest inline (`vite:content`) — nie używaj w nim względnych `url()`.

## Afiliacja

- Oferty: kolekcja `offers` (link partnerski, logo, wyróżnik, kategoria). Nieopublikowana = ukryta.
- Link partnerski to pole `partner_url` (nie `url`, bo w Antlers `url` wpisu przesłania pole).
- Render: `block_offers` (karty `components/offers/card`) i set Bard `offer` w artykułach
  (`components/offers/box`). Linki zawsze `rel="sponsored noopener"`, etykieta „Link partnerski”
  i `components/offers/disclosure` pod ofertami.

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
- Data ostatniej zmiany (`App\Support\Lastmod::of()`; mtime plików jest niewiarygodny, bo deploy = nowe pliki):
  `updated_at` z front-matter (edycje w CP) > manifest `content/.lastmod` (linie `ścieżka<TAB>ISO8601`,
  `merge=union`, czytnik bierze najpóźniejszą datę duplikatu) > data wpisu > `lastModified()`. Manifest utrzymuje
  hook `.githooks/pre-commit` (`php artisan content:lastmod --staged`; włącz raz: `git config core.hooksPath .githooks`),
  pełna przebudowa: `content:lastmod --rebuild`. W szablonach używaj `{{ lastmod }}`, nie `last_modified`.
- Sitemapy: `/sitemap.xml` (index) + `/sitemap-{kolekcja|taksonomia}.xml` (`routes/web.php`,
  widoki `resources/views/sitemaps`). `/robots.txt` z trasy — Herd/Forge nginx ma
  `location = /robots.txt` bez fallbacku do PHP, trzeba go usunąć albo dodać `try_files`.
