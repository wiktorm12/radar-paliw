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

| Element | Handle | Route |
|---|---|---|
| Strony (page builder) | `pages` | `/{slug}` |
| Sieci stacji | `stations` | `/stacje/{slug}` |
| Promocje | `promotions` | `/promocje/{slug}` |
| Rodzaje paliwa (taksonomia) | `fuel_types` | — |
| Ustawienia (global) | `settings` | — |
| Nawigacje | `main`, `footer` | — |

Promocja: `station` (entry), `fuel_types` (terms), `discount_type`
(`cents_per_liter`/`percent`/`fixed_price`/`points`), `discount_value`, `valid_from`,
`valid_to`, `requires_app`, `source_url`, `featured`, `content` (warunki).

Aktywne promocje pobieramy przez query scope `active_promotions`
(`app/Scopes/ActivePromotions.php`): `valid_to >= dziś`, opcjonalnie `stations` i
`fuel_type` przekazane jako parametry tagu `collection`.

## Bloki buildera

`block_hero`, `block_text`, `block_promotions_list` — wspólne partiale fieldsetów:
`partial_header`, `partial_background`, `partial_section_id`.
