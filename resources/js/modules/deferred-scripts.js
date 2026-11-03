const INTERACTION_EVENTS = ['pointerdown', 'keydown', 'touchstart', 'scroll', 'wheel', 'mousemove'];
const FALLBACK_DELAY = 8000;

class DeferredScriptsModule {
    init() {
        if (!document.querySelector('script[data-deferred-src]')) {
            return;
        }

        this.load = this.load.bind(this);

        INTERACTION_EVENTS.forEach((event) => window.addEventListener(event, this.load, { once: true, passive: true }));

        if (document.readyState === 'complete') {
            this.startFallback();
        } else {
            window.addEventListener('load', () => this.startFallback(), { once: true });
        }
    }

    startFallback() {
        this.timer = window.setTimeout(this.load, FALLBACK_DELAY);
    }

    load() {
        INTERACTION_EVENTS.forEach((event) => window.removeEventListener(event, this.load));
        window.clearTimeout(this.timer);

        const loaded = new Set();

        document.querySelectorAll('script[data-deferred-src]').forEach((placeholder) => {
            const src = placeholder.dataset.deferredSrc;

            placeholder.remove();

            if (loaded.has(src)) {
                return;
            }

            loaded.add(src);

            const script = document.createElement('script');

            [...placeholder.attributes]
                .filter((attribute) => !['type', 'data-deferred-src'].includes(attribute.name))
                .forEach((attribute) => script.setAttribute(attribute.name, attribute.value));

            script.src = src;
            script.async = true;
            document.head.append(script);
        });
    }
}

export default new DeferredScriptsModule();
