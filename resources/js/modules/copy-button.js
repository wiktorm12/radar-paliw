const FEEDBACK_DURATION = 2000;

class CopyButton {
    buttonElement = null;
    targetElement = null;
    statusElement = null;
    label = '';
    resetTimeout = null;

    constructor(buttonElement) {
        this.buttonElement = buttonElement;
        this.targetElement = document.getElementById(buttonElement.dataset.copyTarget);
        this.statusElement = buttonElement.parentElement.parentElement.querySelector('[data-copy-status]');
        this.label = buttonElement.textContent;

        if (!this.targetElement || !navigator.clipboard) {
            return;
        }

        buttonElement.hidden = false;
        this.initEvents();
    }

    initEvents() {
        this.buttonElement.addEventListener('click', () => this.copy());
    }

    copy() {
        navigator.clipboard
            .writeText(this.targetElement.textContent.trim())
            .then(() => this.showFeedback('Skopiowano'))
            .catch(() => this.copyBySelection());
    }

    // Zapas, gdy przeglądarka blokuje Clipboard API: zaznacza kod i próbuje execCommand.
    copyBySelection() {
        const range = document.createRange();
        range.selectNodeContents(this.targetElement);

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);

        let copied = false;
        try {
            copied = document.execCommand('copy');
        } catch {
            copied = false;
        }

        this.showFeedback(copied ? 'Skopiowano' : 'Zaznaczono – skopiuj Ctrl+C');
    }

    showFeedback(message) {
        this.buttonElement.textContent = message;

        if (this.statusElement) {
            this.statusElement.textContent = message;
        }

        clearTimeout(this.resetTimeout);
        this.resetTimeout = setTimeout(() => {
            this.buttonElement.textContent = this.label;
        }, FEEDBACK_DURATION);
    }
}

class CopyButtonModule {
    init() {
        document.querySelectorAll('[data-copy-button]').forEach((buttonElement) => new CopyButton(buttonElement));
    }
}

export default new CopyButtonModule();
