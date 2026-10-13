import adSticky from './modules/ad-sticky';
import priceChart from './modules/price-chart';
import promotionFilters from './modules/promotion-filters';

document.documentElement.classList.replace('no-js', 'js');

adSticky.init();
priceChart.init();
promotionFilters.init();
