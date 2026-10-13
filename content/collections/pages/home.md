---
id: 049ed0c0-9796-43f7-b158-4f6129328c12
title: 'Strona główna'
blueprint: page
seo_title: 'Radar Paliw — promocje na paliwo na stacjach w Polsce'
seo_description: 'Wszystkie aktualne promocje na paliwo: Orlen, BP, Shell, Circle K, MOL, Moya, Amic, Auchan i Carrefour. Rabaty, kupony i akcje w aplikacjach — codziennie aktualizowane.'
content:
  -
    id: hero01
    type: block_hero
    enabled: true
    heading: 'Wszystkie promocje na paliwo w jednym miejscu'
    intro: 'Rabaty, kupony i akcje lojalnościowe największych sieci stacji w Polsce — zebrane i aktualne.'
  -
    id: homead01
    type: block_ad
    enabled: true
    placement: home_after_hero
  -
    id: promos01
    type: block_promotions_list
    enabled: true
    heading: 'Aktualne promocje'
    limit: 12
    ad_every: 6
    background_color: gray
  -
    id: stations01
    type: block_stations_grid
    enabled: true
    heading: 'Promocje według sieci'
  -
    id: articles01
    type: block_articles_list
    enabled: true
    heading: 'Najnowsze na blogu'
    limit: 3
    paginate: false
    ad_every: 0
    background_color: gray
  -
    id: homead02
    type: block_ad
    enabled: true
    placement: home_bottom
---
