const moneyFormat = new Intl.NumberFormat('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const formatMoney = (value) => `${moneyFormat.format(value)} zł`;

class CpnCalculator {
    init() {
        document.querySelectorAll('[data-cpn-calculator]').forEach((calculatorElement) => new Calculator(calculatorElement));
    }
}

class Calculator {
    calculatorElement = null;
    formElement = null;
    litresElement = null;
    fuelElement = null;
    resultElement = null;
    prices = {};

    constructor(calculatorElement) {
        this.calculatorElement = calculatorElement;
        this.formElement = calculatorElement.querySelector('[data-cpn-calculator-form]');
        this.litresElement = calculatorElement.querySelector('[data-cpn-calculator-litres]');
        this.fuelElement = calculatorElement.querySelector('[data-cpn-calculator-fuel]');
        this.resultElement = calculatorElement.querySelector('[data-cpn-calculator-result]');

        const pricesElement = calculatorElement.querySelector('[data-cpn-calculator-prices]');

        if (!this.formElement || !this.litresElement || !this.fuelElement || !this.resultElement || !pricesElement) {
            return;
        }

        this.prices = JSON.parse(pricesElement.textContent);
        this.formElement.hidden = false;
        this.initEvents();
        this.update();
    }

    initEvents() {
        this.formElement.addEventListener('input', () => this.update());
        this.formElement.addEventListener('submit', (event) => {
            event.preventDefault();
            this.update();
        });
    }

    update() {
        const fuel = this.prices[this.fuelElement.value];
        const litres = Math.min(1000, Math.max(0, parseFloat(String(this.litresElement.value).replace(',', '.')) || 0));

        if (!fuel) {
            return;
        }

        const cost = litres * fuel.price;
        const costFullVat = litres * fuel.vat23;

        this.resultElement.replaceChildren(
            this.row('Koszt po limicie', formatMoney(cost), 'text-lg font-bold text-zinc-900'),
            this.row('Przy VAT 23%', formatMoney(costFullVat)),
            this.row('Oszczędzasz', formatMoney(costFullVat - cost), 'font-semibold text-green-700'),
        );
    }

    row(label, value, valueClass = 'font-semibold text-zinc-900') {
        const rowElement = document.createElement('p');
        const valueElement = document.createElement('span');

        rowElement.className = 'flex items-baseline justify-between gap-4';
        valueElement.className = `tabular-nums ${valueClass}`;
        valueElement.textContent = value;
        rowElement.append(label, valueElement);

        return rowElement;
    }
}

export default new CpnCalculator();
