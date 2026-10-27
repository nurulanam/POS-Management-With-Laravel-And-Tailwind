function money(value, config) {
    return `${config.currency} ${config.precision === 0 ? value : `${value / 100n}.${String(value % 100n).padStart(2, '0')}`}`;
}
function parseCost(value, precision) {
    const pattern = precision === 0 ? /^\d{1,13}$/ : /^\d{1,13}(?:\.\d{1,2})?$/;
    if (!pattern.test(value.trim())) return null;
    const [whole, fraction = ''] = value.trim().split('.');
    return precision === 0 ? BigInt(whole) : BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}
const orderForm = document.getElementById('purchase-form');
if (orderForm) {
    const config = JSON.parse(orderForm.dataset.config);
    const items = new Map(config.items.map(item => [String(item.id), item]));
    const lines = document.getElementById('purchase-lines');
    const submit = document.getElementById('purchase-submit');
    const notice = document.getElementById('purchase-message');
    const search = document.getElementById('purchase-search');
    const catalog = document.getElementById('purchase-catalog');
    const catalogMessage = document.getElementById('purchase-catalog-message');
    let submitting = false, controller, timer;
    function update() {
        let total = 0n, valid = true;
        items.forEach(item => {
            const cost = parseCost(String(item.unit_cost), config.precision);
            const quantity = Number(item.quantity);
            if (cost === null || cost > 1000000000n || !Number.isInteger(quantity) || quantity < 1 || quantity > 10000) valid = false;
            else total += cost * BigInt(quantity);
        });
        if (total > 1000000000000n) valid = false;
        document.getElementById('purchase-total').textContent = money(total, config);
        submit.disabled = submitting || !items.size || !valid || !document.getElementById('purchase-confirmed').checked;
        notice.textContent = !items.size ? 'Add at least one product.' : !valid ? 'Check whole quantities, unit costs, and supported limits before ordering.' : `${items.size} product lines. Review original costs before saving.`;
    }
    function render() {
        lines.replaceChildren();
        items.forEach((item, id) => {
            const row = document.createElement('div'); row.className = 'purchase-line';
            const title = document.createElement('strong'); title.textContent = `${item.name} · ${item.sku}`;
            const fields = document.createElement('div'); fields.className = 'form-grid';
            for (const [key, text, attrs] of [
                ['quantity', `Order quantity for ${item.name}`, { type: 'number', min: '1', max: '10000', step: '1' }],
                ['unit_cost', `Unit cost for ${item.name}`, { type: 'text', inputMode: 'decimal', maxLength: 20 }],
            ]) {
                const field = document.createElement('div'); field.className = 'field';
                const input = document.createElement('input');
                Object.assign(input, { ...attrs, name: `items[${id}][${key}]`, id: `po-${key}-${id}`, value: item[key], required: true });
                const label = document.createElement('label'); label.htmlFor = input.id; label.textContent = text;
                input.addEventListener('input', () => { item[key] = input.value; update(); });
                field.append(label, input); fields.append(field);
            }
            const hidden = document.createElement('input');
            Object.assign(hidden, { type: 'hidden', name: `items[${id}][product_id]`, value: id });
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'text-link'; remove.textContent = `Remove ${item.name}`;
            remove.addEventListener('click', () => { items.delete(id); render(); });
            row.append(title, hidden, fields, remove); lines.append(row);
        });
        update();
    }
    async function load() {
        controller?.abort();
        const pending = new AbortController(); controller = pending;
        catalogMessage.textContent = 'Finding products…';
        try {
            const url = new URL(config.catalogUrl, window.location.origin);
            url.search = new URLSearchParams({ q: search.value, branch_id: config.branchId });
            const response = await fetch(url, { signal: pending.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Catalog unavailable. Reload to check your branch or session.');
            const data = await response.json();
            catalog.replaceChildren();
            data.products.forEach(product => {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline';
                button.textContent = `${product.name} · ${product.sku}`; button.setAttribute('aria-label', `Order ${product.name}`);
                button.addEventListener('click', () => {
                    if (items.has(String(product.id))) { notice.textContent = 'This product is already on the order. Change its quantity below.'; return; }
                    if (items.size >= 100) { notice.textContent = 'An order may contain at most 100 products.'; return; }
                    items.set(String(product.id), { ...product, quantity: 1, unit_cost: '' }); render();
                });
                catalog.append(button);
            });
            catalogMessage.textContent = data.next_page ? 'First 24 matches. Refine your search to find another product.' : data.products.length ? 'Choose products, then enter agreed costs below.' : 'No active products found. Add a product or refine your search.';
        } catch (error) {
            if (error.name !== 'AbortError') { catalog.replaceChildren(); catalogMessage.textContent = error.message; }
        }
    }
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 220); });
    search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); clearTimeout(timer); load(); } });
    document.getElementById('purchase-confirmed').addEventListener('change', update);
    orderForm.addEventListener('submit', event => { update(); if (submit.disabled) event.preventDefault(); else { submitting = true; update(); } });
    window.addEventListener('pageshow', () => { submitting = false; update(); });
    render(); load();
}
const receiveForm = document.getElementById('purchase-receive-form');
if (receiveForm) {
    const config = JSON.parse(receiveForm.dataset.config);
    const lines = new Map(config.lines.map(line => [String(line.id), line]));
    const submit = document.getElementById('receiving-submit');
    let submitting = false;
    function update() {
        let total = 0n, count = 0, valid = true;
        receiveForm.querySelectorAll('[data-receive-line]').forEach(input => {
            const line = lines.get(input.dataset.receiveLine), quantity = Number(input.value);
            if (!Number.isInteger(quantity) || quantity < 0 || quantity > line.quantity - line.received_quantity) valid = false;
            else { total += BigInt(line.unit_cost_minor) * BigInt(quantity); count += quantity; }
        });
        document.getElementById('receiving-total').textContent = money(total, config);
        document.getElementById('receiving-message').textContent = !valid ? 'Check quantities against the remaining units.' : `${count} units selected for stock.`;
        submit.disabled = submitting || !valid || !count || !document.getElementById('receiving-confirmed').checked;
    }
    receiveForm.addEventListener('input', update); receiveForm.addEventListener('change', update);
    document.getElementById('receive-all').addEventListener('click', () => { receiveForm.querySelectorAll('[data-receive-line]').forEach(input => { input.value = input.max; }); update(); });
    receiveForm.addEventListener('submit', event => { update(); if (submit.disabled) event.preventDefault(); else { submitting = true; update(); } });
    window.addEventListener('pageshow', () => { submitting = false; update(); }); update();
}
document.querySelectorAll('.purchase-operation').forEach(form => {
    form.addEventListener('submit', () => { form.querySelector('button[type="submit"], button:not([type])').disabled = true; });
    window.addEventListener('pageshow', () => { form.querySelectorAll('button').forEach(button => { button.disabled = false; }); });
});
