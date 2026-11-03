import adSticky from './modules/ad-sticky';
import deferredScripts from './modules/deferred-scripts';
import headerNav from './modules/header-nav';
import promotionFilters from './modules/promotion-filters';

document.documentElement.classList.replace('no-js', 'js');

adSticky.init();
deferredScripts.init();
headerNav.init();
promotionFilters.init();

if (document.querySelector('[data-price-chart]')) {
    import('./modules/price-chart').then((module) => module.default.init());
}

if (document.querySelector('[data-cpn-calculator]')) {
    import('./modules/cpn-calculator').then((module) => module.default.init());
}

if (document.querySelector('[data-copy-button]')) {
    import('./modules/copy-button').then((module) => module.default.init());
}
