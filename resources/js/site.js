import adSticky from './modules/ad-sticky';
import headerNav from './modules/header-nav';
import promotionFilters from './modules/promotion-filters';

document.documentElement.classList.replace('no-js', 'js');

adSticky.init();
headerNav.init();
promotionFilters.init();

if (document.querySelector('[data-price-chart]')) {
    import('./modules/price-chart').then((module) => module.default.init());
}
