class PromotionFilters {
    constructor(formElement) {
        this.formElement = formElement;

        this.initEvents();
    }

    initEvents() {
        this.formElement.addEventListener('submit', (event) => {
            event.preventDefault();
            this.submit();
        });
    }

    submit() {
        const formData = new FormData(this.formElement);
        const params = new URLSearchParams();

        formData.forEach((value, key) => {
            if (value && !(key === 'sortuj' && value === 'ending')) {
                params.append(key, value);
            }
        });

        const query = params.toString();

        window.location.href = query ? `${this.formElement.action}?${query}` : this.formElement.action;
    }
}

class PromotionFiltersModule {
    init() {
        document.querySelectorAll('[data-promotion-filters]').forEach((formElement) => new PromotionFilters(formElement));
    }
}

export default new PromotionFiltersModule();
