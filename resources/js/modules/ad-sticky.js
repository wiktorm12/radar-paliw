class AdSticky {
    constructor(element) {
        this.element = element;
        this.closeButton = element.querySelector('[data-ad-sticky-close]');

        this.initEvents();
    }

    close() {
        document.querySelector('[data-ad-sticky-spacer]')?.remove();
        this.element.remove();
    }

    initEvents() {
        this.closeButton?.addEventListener('click', () => this.close());
    }
}

class AdStickyModule {
    init() {
        document.querySelectorAll('[data-ad-sticky]').forEach((element) => new AdSticky(element));
    }
}

export default new AdStickyModule();
