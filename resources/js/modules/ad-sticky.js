class AdSticky {
    constructor(element) {
        this.element = element;
        this.closeButton = element.querySelector('[data-ad-sticky-close]');

        this.initEvents();
    }

    initEvents() {
        this.closeButton?.addEventListener('click', () => this.element.remove());
    }
}

class AdStickyModule {
    init() {
        document.querySelectorAll('[data-ad-sticky]').forEach((element) => new AdSticky(element));
    }
}

export default new AdStickyModule();
