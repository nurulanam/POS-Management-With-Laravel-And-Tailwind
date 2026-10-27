const dispatchForm = document.getElementById('transfer-dispatch-form');
if (dispatchForm) {
    const destination = document.getElementById('destination_branch_id');
    const search = document.getElementById('transfer-search');
    const catalog = document.getElementById('transfer-catalog');
    const message = document.getElementById('transfer-catalog-message');
    const lines = document.getElementById('transfer-lines');
    const submit = document.getElementById('transfer-submit');
    const items = new Map();
    let controller, timer, submitting = false;
    function update() {
        let count = 0, valid = true;
        items.forEach(item => {
            const quantity = Number(item.quantity);
            if (!Number.isInteger(quantity) || quantity < 1 || quantity > Math.min(10000, item.stock)) valid = false;
            else count += quantity;
        });
        document.getElementById('transfer-summary').textContent = valid ? `${count} units in ${items.size} lines will leave source stock.` : 'Check whole quantities against available source stock.';
        submit.disabled = submitting || !destination.value || !items.size || !valid || !document.getElementById('dispatch-confirmed').checked;
    }
    function render() {
        lines.replaceChildren();
        items.forEach(item => {
            const row = document.createElement('div'); row.className = 'purchase-line';
            const name = document.createElement('strong'); name.textContent = `${item.name} → ${item.target.name}`;
            const detail = document.createElement('small'); detail.className = 'table-note'; detail.textContent = `${item.sku} · ${item.stock} available at source`;
            const field = document.createElement('div'); field.className = 'field section-gap';
            const input = document.createElement('input'); Object.assign(input, { id: `transfer-qty-${item.id}`, name: `items[${item.id}][quantity]`, type: 'number', min: '1', max: String(Math.min(10000, item.stock)), step: '1', required: true, value: item.quantity });
            const label = document.createElement('label'); label.htmlFor = input.id; label.textContent = `Dispatch quantity for ${item.name}`;
            input.addEventListener('input', () => { item.quantity = input.value; update(); });
            field.append(label, input); row.append(name, detail, field);
            for (const [key, value] of Object.entries({ source_product_id: item.id, destination_product_id: item.target.id })) {
                const hidden = document.createElement('input'); Object.assign(hidden, { type: 'hidden', name: `items[${item.id}][${key}]`, value }); row.append(hidden);
            }
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'text-link'; remove.textContent = `Remove ${item.name}`;
            remove.addEventListener('click', () => { items.delete(item.id); render(); }); row.append(remove); lines.append(row);
        }); update();
    }
    async function load() {
        controller?.abort(); catalog.replaceChildren();
        if (!destination.value) { message.textContent = 'Select a destination first.'; return; }
        const pending = new AbortController(); controller = pending;
        message.textContent = 'Finding SKU matches…';
        try {
            const url = new URL(dispatchForm.dataset.catalog, window.location.origin);
            url.search = new URLSearchParams({ destination_branch_id: destination.value, branch_id: dispatchForm.dataset.branch, q: search.value });
            const response = await fetch(url, { signal: pending.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Catalog unavailable. Reload and check branch access.');
            const data = await response.json();
            if (controller !== pending || pending.signal.aborted) return;
            data.products.forEach(product => {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline';
                button.textContent = `${product.sku}: ${product.name} → ${product.target?.name ?? 'No active destination SKU match'}`;
                button.setAttribute('aria-label', `Transfer ${product.name}`); button.disabled = !product.target || !product.stock;
                button.addEventListener('click', () => {
                    if (items.has(product.id)) return;
                    if (items.size >= 100) { message.textContent = 'At most 100 products per transfer.'; return; }
                    items.set(product.id, { ...product, quantity: 1 }); render();
                }); catalog.append(button);
            });
            message.textContent = data.more ? 'First 24 matches. Refine your search.' : 'Only active matching SKUs with source stock can be selected. Verify both names and units.';
        } catch (error) { if (error.name !== 'AbortError') message.textContent = error.message; }
    }
    destination.addEventListener('change', () => { items.clear(); document.getElementById('dispatch-confirmed').checked = false; render(); load(); });
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 200); });
    search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); clearTimeout(timer); load(); } });
    document.getElementById('dispatch-confirmed').addEventListener('change', update);
    dispatchForm.addEventListener('submit', event => { update(); if (submit.disabled) event.preventDefault(); else { submitting = true; update(); } });
    window.addEventListener('pageshow', () => { submitting = false; update(); }); render();
}
const receiptForm = document.getElementById('transfer-receive-form');
if (receiptForm) {
    const submit = document.getElementById('transfer-receive-submit');
    const discrepancy = document.getElementById('discrepancy-confirmed');
    let submitting = false;
    function update() {
        let accepted = 0, damaged = 0, missing = 0, valid = true;
        receiptForm.querySelectorAll('[data-transfer-line]').forEach(row => {
            const values = [...row.querySelectorAll('[data-disposition]')].map(input => Number(input.value));
            if (values.some(n => !Number.isInteger(n) || n < 0) || values.reduce((a, b) => a + b, 0) > Number(row.dataset.remaining)) valid = false;
            else { accepted += values[0]; damaged += values[1]; missing += values[2]; }
        });
        const loss = damaged + missing;
        document.getElementById('transfer-discrepancy').hidden = !loss;
        discrepancy.disabled = !loss; discrepancy.required = Boolean(loss);
        document.getElementById('transfer-receive-summary').textContent = valid ? `${accepted} accepted into stock · ${damaged} damaged · ${missing} confirmed missing.` : 'Check dispositions: their combined quantity cannot exceed units in transit.';
        submit.disabled = submitting || !valid || (!accepted && !loss) || !document.getElementById('transfer-receive-confirmed').checked || Boolean(loss) && !discrepancy.checked;
    }
    receiptForm.addEventListener('input', update); receiptForm.addEventListener('change', update);
    document.getElementById('transfer-accept-all').addEventListener('click', () => {
        receiptForm.querySelectorAll('[data-transfer-line]').forEach(row => {
            row.querySelectorAll('[data-disposition]').forEach(input => { input.value = input.dataset.disposition === 'received_quantity' ? row.dataset.remaining : '0'; });
        }); discrepancy.checked = false; update();
    });
    receiptForm.addEventListener('submit', event => { update(); if (submit.disabled) event.preventDefault(); else { submitting = true; update(); } });
    window.addEventListener('pageshow', () => { submitting = false; update(); }); update();
}
