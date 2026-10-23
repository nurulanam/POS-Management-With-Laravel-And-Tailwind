document.querySelector('[data-print-receipt]')?.addEventListener('click', () => window.print());

const app = document.getElementById('pos-app');
if (app) {
    const config = JSON.parse(app.dataset.config);
    const cart = new Map(config.cart.map(item => [item.id, item]));
    const grid = document.getElementById('catalog-grid');
    const message = document.getElementById('catalog-message');
    const more = document.getElementById('catalog-more');
    const search = document.getElementById('catalog-search');
    const lines = document.getElementById('cart-lines');
    const inputs = document.getElementById('cart-inputs');
    const notice = document.getElementById('cart-notice');
    const tender = document.getElementById('tendered');
    const submit = document.getElementById('checkout-submit');
    let controller;
    let nextPage = null;
    let total = 0n;
    let submitting = false;

    function amount(value) {
        const minor = BigInt(value);
        return `${config.currency} ${config.precision === 0 ? minor : `${minor / 100n}.${String(minor % 100n).padStart(2, '0')}`}`;
    }
    function cashAmount() {
        const value = tender.value.trim();
        const pattern = config.precision === 0 ? /^\d{1,13}$/ : /^\d{1,13}(?:\.\d{1,2})?$/;
        if (!pattern.test(value)) return null;
        const [whole, fraction = ''] = value.split('.');
        return config.precision === 0 ? BigInt(whole) : BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
    }
    function updateTotals() {
        const subtotal = [...cart.values()].reduce((sum, item) => sum + BigInt(item.price_minor) * BigInt(item.quantity), 0n);
        const tax = (subtotal * BigInt(config.taxBasisPoints) + 5000n) / 10000n;
        total = subtotal + tax;
        document.getElementById('cart-subtotal').textContent = amount(subtotal);
        document.getElementById('cart-tax').textContent = amount(tax);
        document.getElementById('cart-total').textContent = amount(total);
        const cash = cashAmount();
        document.getElementById('cart-change').textContent = cash === null ? 'Enter cash received' : cash < total ? `Short ${amount(total - cash)}` : amount(cash - total);
        const invalidStock = [...cart.values()].some(item => item.quantity > item.stock);
        submit.disabled = submitting || cart.size === 0 || cash === null || cash < total || cash > 2000000000000n || subtotal > 1000000000000n || invalidStock;
        notice.textContent = !cart.size ? 'Your cart is empty. Choose a product to begin.' : invalidStock ? 'Some quantities exceed available stock. Reduce them before checkout.' : `${cart.size} product${cart.size === 1 ? '' : 's'} in this sale.`;
    }
    function renderCart() {
        lines.replaceChildren();
        inputs.replaceChildren();
        [...cart.values()].forEach((item, index) => {
            const row = document.createElement('div');
            row.className = 'cart-item';
            const info = document.createElement('div');
            const name = document.createElement('strong');
            name.textContent = item.name;
            const price = document.createElement('small');
            price.textContent = `${item.sku} · ${amount(item.price_minor)} each`;
            info.append(name, price);
            const controls = document.createElement('div');
            controls.className = 'cart-item-controls';
            const qty = document.createElement('input');
            Object.assign(qty, { type: 'number', min: '1', max: String(Math.min(item.stock, 10000)), step: '1', value: String(item.quantity) });
            qty.setAttribute('aria-label', `Quantity for ${item.name}`);
            qty.addEventListener('change', () => {
                const value = Number(qty.value);
                item.quantity = Number.isInteger(value) ? Math.min(10000, Math.max(1, value)) : 1;
                renderCart();
            });
            const remove = document.createElement('button');
            remove.type = 'button'; remove.className = 'text-link'; remove.textContent = 'Remove';
            remove.setAttribute('aria-label', `Remove ${item.name}`);
            remove.addEventListener('click', () => { cart.delete(item.id); renderCart(); });
            controls.append(qty, remove);
            row.append(info, controls);
            lines.append(row);
            for (const [key, value] of Object.entries({ product_id: item.id, quantity: item.quantity, expected_price_minor: item.price_minor })) {
                const hidden = document.createElement('input');
                Object.assign(hidden, { type: 'hidden', name: `items[${index}][${key}]`, value: String(value) });
                inputs.append(hidden);
            }
        });
        updateTotals();
    }
    function addProduct(product) {
        const current = cart.get(product.id);
        if (!current && cart.size >= 100) { notice.textContent = 'A sale can contain at most 100 different products.'; return; }
        const quantity = (current?.quantity ?? 0) + 1;
        if (quantity > Math.min(product.stock, 10000)) { notice.textContent = 'No more units are available for this product.'; return; }
        cart.set(product.id, { ...product, quantity });
        renderCart();
    }
    async function loadCatalog(page = 1) {
        controller?.abort();
        controller = new AbortController();
        more.disabled = true;
        message.textContent = 'Loading products…';
        try {
            const url = new URL(config.catalogUrl, window.location.origin);
            url.search = new URLSearchParams({ q: search.value, page, branch_id: config.branchId });
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok) throw new Error('Catalog unavailable. Your branch or session may have changed. Reload this page to continue.');
            const data = await response.json();
            if (page === 1) grid.replaceChildren();
            data.products.forEach(product => {
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'catalog-product'; button.disabled = product.stock === 0;
                button.setAttribute('aria-label', `Add ${product.name} to cart`);
                const category = document.createElement('small'); category.textContent = product.category || 'PRODUCT';
                const name = document.createElement('strong'); name.textContent = product.name;
                const sku = document.createElement('span'); sku.className = 'muted'; sku.textContent = product.sku;
                const price = document.createElement('b'); price.textContent = amount(product.price_minor);
                const stock = document.createElement('span'); stock.className = 'badge badge-neutral'; stock.textContent = `${product.stock} in stock`;
                button.append(category, name, sku, price, stock);
                button.addEventListener('click', () => addProduct(product));
                grid.append(button);
            });
            nextPage = data.next_page;
            more.hidden = nextPage === null;
            more.textContent = 'Load more products';
            message.textContent = grid.childElementCount ? `${grid.childElementCount} products shown. Prices exclude tax.` : 'No products found. Ask a manager to add stock or try another search.';
        } catch (error) {
            if (error.name === 'AbortError') return;
            message.textContent = error.message || 'Unable to load products. Try again.';
            more.hidden = false; more.textContent = 'Retry catalog'; nextPage = null;
        } finally { more.disabled = false; }
    }
    let timer;
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => loadCatalog(), 200); });
    search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); clearTimeout(timer); loadCatalog(); } });
    more.addEventListener('click', () => loadCatalog(nextPage ?? 1));
    tender.addEventListener('input', updateTotals);
    document.getElementById('cart-clear').addEventListener('click', () => { cart.clear(); renderCart(); });
    document.getElementById('checkout-form').addEventListener('submit', event => {
        updateTotals();
        if (submit.disabled) { event.preventDefault(); return; }
        submitting = true;
        submit.disabled = true;
        submit.textContent = 'Recording sale…';
    });
    window.addEventListener('pageshow', () => { submitting = false; submit.textContent = 'Complete cash sale →'; updateTotals(); });
    renderCart();
    loadCatalog();
}
