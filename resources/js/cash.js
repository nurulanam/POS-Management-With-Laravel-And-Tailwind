// Server-side idempotency remains authoritative; prevent accidental double clicks as well.
document.querySelectorAll('.cash-operation').forEach(form => {
    form.addEventListener('submit', () => {
        form.querySelectorAll('button').forEach(button => { button.disabled = true; });
    });
    window.addEventListener('pageshow', () => {
        form.querySelectorAll('button').forEach(button => { button.disabled = false; });
    });
});
const closeForm = document.getElementById('cash-close-form');
if (closeForm) {
    const input = document.getElementById('counted');
    const output = document.getElementById('cash-variance-preview');
    const precision = Number(closeForm.dataset.precision);
    const expected = BigInt(closeForm.dataset.expected);
    input.addEventListener('input', () => {
        const value = input.value.trim();
        const pattern = precision === 0 ? /^\d{1,13}$/ : /^\d{1,13}(?:\.\d{1,2})?$/;
        if (!pattern.test(value)) { output.textContent = 'Enter a valid non-negative cash count.'; return; }
        const [whole, fraction = ''] = value.split('.');
        const counted = precision === 0 ? BigInt(whole) : BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
        if (counted > 2000000000000n) { output.textContent = 'This count exceeds the supported limit.'; return; }
        const variance = counted - expected;
        const absolute = variance < 0n ? -variance : variance;
        const amount = precision === 0 ? String(absolute) : `${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`;
        output.textContent = variance === 0n ? 'Balanced · No variance' : `${variance < 0n ? 'Short' : 'Over'} ${closeForm.dataset.currency} ${amount}`;
    });
}
