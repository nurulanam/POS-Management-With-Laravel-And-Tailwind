const refundForm = document.getElementById('refund-form');
if (refundForm) {
    const config = JSON.parse(refundForm.dataset.config);
    const lines = new Map(config.lines.map(line => [String(line.id), line]));
    const submit = document.getElementById('refund-submit');
    const confirmation = document.getElementById('confirmed');
    let submitting = false;
    function money(value) {
        return `${config.currency} ${config.precision === 0 ? value : `${value / 100n}.${String(value % 100n).padStart(2, '0')}`}`;
    }
    function share(amount, quantity, sold) {
        return (BigInt(amount) * BigInt(quantity) * 2n + BigInt(sold)) / (BigInt(sold) * 2n);
    }
    function update() {
        let subtotal = 0n, tax = 0n, count = 0, valid = true;
        refundForm.querySelectorAll('[data-line-id]').forEach(row => {
            const line = lines.get(row.dataset.lineId);
            const input = row.querySelector('[data-return-quantity]');
            const quantity = Number(input.value);
            if (!Number.isInteger(quantity) || quantity < 0 || quantity > line.sold - line.returned) {
                valid = false;
                row.querySelector('[data-line-refund]').textContent = 'Check quantity';
                return;
            }
            const net = share(line.subtotal_minor, line.returned + quantity, line.sold) - share(line.subtotal_minor, line.returned, line.sold);
            const returnedTax = share(line.tax_minor, line.returned + quantity, line.sold) - share(line.tax_minor, line.returned, line.sold);
            subtotal += net; tax += returnedTax; count += quantity;
            row.querySelector('[data-line-refund]').textContent = money(net + returnedTax);
        });
        document.getElementById('refund-subtotal').textContent = money(subtotal);
        document.getElementById('refund-tax').textContent = money(tax);
        document.getElementById('refund-total').textContent = money(subtotal + tax);
        document.getElementById('refund-message').textContent = !valid ? 'Check return quantities.' : count ? `${count} units selected. Unchecked items will not be restocked.` : 'Choose at least one unit.';
        submit.disabled = submitting || !valid || !count || !confirmation.checked;
    }
    refundForm.addEventListener('input', update);
    refundForm.addEventListener('change', update);
    document.getElementById('refund-select-all').addEventListener('click', () => {
        refundForm.querySelectorAll('[data-return-quantity]').forEach(input => { input.value = input.max; });
        update();
    });
    refundForm.addEventListener('submit', event => {
        update();
        if (submit.disabled) { event.preventDefault(); return; }
        submitting = true;
        submit.disabled = true;
        submit.textContent = 'Recording refund…';
    });
    window.addEventListener('pageshow', () => { submitting = false; submit.textContent = 'Record cash refund →'; update(); });
    update();
}
