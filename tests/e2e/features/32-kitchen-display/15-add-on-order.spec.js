import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addToCart, finish } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');
const CANCELLED = 'Pesanan asal sudah dibatalkan — buat pesanan baru';

test.describe('32-kitchen-display', () => {
    test.describe.configure({ timeout: 240_000 });

    test('Tambah pesanan on a receipt makes an add-on that joins the original order on the display once it is paid', { tag: ['@REQ-003-AC-21'] }, async ({ asRole, arrange, uniq }) => {
        const original = arrange('kitchen-sale', { prefix: uniq('Asal'), flagged: 1 });
        const title = uniq('Susulan');
        arrange('kitchen.flag', { product_id: arrange('product', { title, stock: 10 }).product_id });
        const bank = arrange('pos.bank-account', { bank: uniq('BCA') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await page.goto(`/dashboard/transactions/${original.invoice}/print`);
        await page.getByRole('button', { name: 'Tambah pesanan' }).click();
        const banner = page.getByText(`Pesanan tambahan untuk ${original.invoice}`);
        await expect(banner).toBeVisible({ timeout: 45_000 });
        await page.waitForLoadState('networkidle');
        await addToCart(page, title);
        await expect(banner).toBeVisible(); // the add-on survives filling the cart
        await page.getByRole('button', { name: 'Transfer Bank' }).click();
        await page.getByRole('button', { name: new RegExp(bank.bank) }).click();
        const invoice = await finish(page);
        await expect(page.getByText(`Tambahan untuk ${original.invoice}`)).toBeVisible();

        // Pay now: nothing of the add-on reaches the kitchen before it is paid.
        await page.goto('/dashboard/kitchen');
        const card = page.locator('article', { hasText: original.invoice });
        await expect(card).toContainText(original.flagged[0]);
        await expect(card.getByRole('heading', { name: 'Tambahan' })).toHaveCount(0);

        arrange('kitchen.set-status', { invoice, status: 'paid' });
        await expect(card.getByRole('heading', { name: 'Tambahan' })).toBeVisible({ timeout: 15_000 });
        await expect(card).toContainText(title);
        await expect(page.locator('article', { hasText: invoice })).toHaveCount(0);
    });

    test('an add-on to a cancelled order is refused on the POS', { tag: ['@REQ-003-AC-21'] }, async ({ asRole, arrange, uniq }) => {
        const original = arrange('kitchen-sale', { prefix: uniq('Batal'), flagged: 1 });
        arrange('kitchen.set-status', { invoice: original.invoice, status: 'failed' });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await page.goto(`/dashboard/transactions?add_on_to=${original.invoice}`);

        await expect(page.getByRole('alert').filter({ hasText: CANCELLED })).toBeVisible({ timeout: 45_000 });
    });
});
