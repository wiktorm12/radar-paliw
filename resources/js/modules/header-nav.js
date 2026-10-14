import {BREAKPOINTS, isBreakpointUp} from '../utils/dom';

class HeaderNav {
    constructor(detailsElement) {
        this.detailsElement = detailsElement;

        this.isDesktop = isBreakpointUp(BREAKPOINTS.MD);
        this.detailsElement.open = this.isDesktop;
        this.initEvents();
    }

    initEvents() {
        window.addEventListener('resize', () => this.sync());
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.detailsElement.open && !isBreakpointUp(BREAKPOINTS.MD)) {
                this.detailsElement.open = false;
                this.detailsElement.querySelector('summary').focus();
            }
        });
        document.addEventListener('click', (event) => {
            if (this.detailsElement.open && !this.detailsElement.contains(event.target) && !isBreakpointUp(BREAKPOINTS.MD)) {
                this.detailsElement.open = false;
            }
        });
    }

    sync() {
        const isDesktop = isBreakpointUp(BREAKPOINTS.MD);

        if (isDesktop !== this.isDesktop) {
            this.isDesktop = isDesktop;
            this.detailsElement.open = isDesktop;
        }
    }
}

class HeaderNavModule {
    init() {
        document.querySelectorAll('[data-header-nav]').forEach((detailsElement) => new HeaderNav(detailsElement));
    }
}

export default new HeaderNavModule();
