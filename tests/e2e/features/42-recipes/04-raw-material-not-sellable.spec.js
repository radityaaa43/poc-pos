import { test, expect } from '../../support/fixtures.js';
import { role, posSearch, posCustomer, posPayExact } from '../../support/inventory.js';

test.describe('42-recipes', () => {
    test('a raw material cannot be searched, added or checked out at the POS', { tag: ['@REQ-001-AC-05'] }, async ({ asRole, arrange, uniq }) => {
        const prefix = uniq('Mentah');
        const customer = uniq('Pelanggan');
        const { bean, bean_id } = arrange('inventory.recipe', { prefix, beans: 500, regular: 3 });
        arrange('inventory.customer', { name: customer });
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(role('cashier'));

        // Not offered: the list holds the regular product only.
        await page.goto('/dashboard/transactions');
        await posSearch(page, prefix);
        await expect(page.getByRole('heading', { name: `${prefix} Croissant` })).toBeVisible();
        await expect(page.getByRole('heading', { name: bean })).toHaveCount(0);

        // Not accepted: the add-to-cart request a stale client or a scanner would send gets a refusal. The page
        // sends it as an Inertia visit, so the same call is made from the page.
        const refused = await page.evaluate(async (productId) => {
            const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1]);
            const response = await fetch('/dashboard/transactions/addToCart', {
                method: 'POST',
                headers: { 'X-Inertia': 'true', 'X-Inertia-Version': JSON.parse(document.querySelector('script[data-page="app"]').textContent).version, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
                body: JSON.stringify({ product_id: productId, qty: 1 }),
            });

            return response.ok;
        }, bean_id);
        // The cashier is not told why: the flash never reaches the page (issue #135), so the empty cart is the proof.
        expect(refused).toBe(true);
        await page.reload();
        await expect(page.getByText('Keranjang kosong', { exact: true })).toBeVisible();

        // Not checked out: a cart that already holds one is rejected and no sale is made.
        arrange('inventory.cart-raw-material', { prefix });
        await page.reload();
        await expect(page.getByText(`${prefix} Bahan`)).toBeVisible();
        await posCustomer(page, customer);
        await posPayExact(page);
        await expect(page.getByText('Gagal menyimpan transaksi')).toBeVisible();
        await expect(page.getByText(`${prefix} Bahan`)).toBeVisible();
    });
});
