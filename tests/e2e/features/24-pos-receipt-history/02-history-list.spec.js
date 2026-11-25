import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { rupiah } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');
const superAdmin = roles.find((role) => role.key === 'super-admin');

const day = (offset) => new Date(Date.now() + offset * 86_400_000).toISOString().slice(0, 10);

function arrangeSale(arrange, uniq, { qty = 1 } = {}) {
    const product = arrange('product', { title: uniq('history'), stock: 10 });
    const customer = arrange('pos.customer', { name: uniq('Ibu Sari') });
    arrange('pos.open-shift', { cash: 100000 });
    const { invoice } = arrange('pos.sale', { product_id: product.product_id, qty, customer_id: customer.customer_id });

    return { product, customer, invoice };
}

test.describe('POS history: list, filters and who sees what', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a sale is listed with cashier, customer, items, total and status; the print icon opens its receipt', async ({ asRole, arrange, uniq }) => {
        const { customer, invoice } = arrangeSale(arrange, uniq, { qty: 3 });
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions/history');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Riwayat Transaksi' })).toBeVisible();
        const row = page.getByRole('row').filter({ hasText: invoice });
        await expect(row).toContainText('E2E Cashier');
        await expect(row).toContainText(customer.name);
        await expect(row).toContainText('3');
        await expect(row).toContainText(rupiah(30000));
        await expect(row).toContainText('Lunas');

        await row.getByRole('link', { name: 'Cetak Struk' }).click();
        await expect(page).toHaveURL(new RegExp(`/dashboard/transactions/${invoice}/print$`));
        await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
    });

    // Bug #134: Tanggal is the raw UTC timestamp.
    test.fixme('the history shows the sale date readably', async ({ asRole, arrange, uniq }) => {
        const { invoice } = arrangeSale(arrange, uniq);
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions/history');
        const row = page.getByRole('row').filter({ hasText: invoice });
        await expect(row).not.toContainText('T0');
        await expect(row).not.toContainText('.000000Z');
    });

    test('filters narrow the list by invoice number and by date, and say so when nothing matches', async ({ asRole, arrange, uniq }) => {
        const first = arrangeSale(arrange, uniq);
        const second = arrangeSale(arrange, uniq);
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions/history');
        await expect(page.getByRole('row').filter({ hasText: first.invoice })).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: second.invoice })).toBeVisible();

        await page.getByRole('button', { name: 'Filter' }).click();
        await page.getByPlaceholder('TRX-...').fill(first.invoice);
        await page.getByRole('button', { name: 'Cari' }).click();
        await expect(page.getByRole('row').filter({ hasText: first.invoice })).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: second.invoice })).toHaveCount(0);

        // A date range around today keeps it; a range that ended days ago drops it.
        await page.getByRole('button', { name: 'Filter' }).click();
        const dates = page.locator('input[type="date"]');
        await dates.nth(0).fill(day(-1));
        await dates.nth(1).fill(day(1));
        await page.getByRole('button', { name: 'Cari' }).click();
        await expect(page.getByRole('row').filter({ hasText: first.invoice })).toBeVisible();

        await page.getByRole('button', { name: 'Filter' }).click();
        await dates.nth(0).fill(day(-10));
        await dates.nth(1).fill(day(-5));
        await page.getByRole('button', { name: 'Cari' }).click();
        await expect(page.getByText('Tidak ada transaksi sesuai filter.')).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: first.invoice })).toHaveCount(0);
    });

    test('a cashier sees only their own sales; a super admin sees everyone\'s', async ({ asRole, arrange, uniq }) => {
        const mine = arrangeSale(arrange, uniq);
        // A sale rung up by the super admin on their own shift.
        const product = arrange('product', { title: uniq('history-admin'), stock: 10 });
        arrange('pos.open-shift', { cash: 100000, cashier: 'superadmin@e2e.test' });
        const theirs = arrange('pos.sale', { product_id: product.product_id, cashier: 'superadmin@e2e.test' });

        const own = await asRole(cashier);
        await own.goto('/dashboard/transactions/history');
        await expect(own.getByRole('row').filter({ hasText: mine.invoice })).toBeVisible();
        await expect(own.getByRole('row').filter({ hasText: theirs.invoice })).toHaveCount(0);

        const admin = await asRole(superAdmin);
        await admin.goto('/dashboard/transactions/history');
        await expect(admin.getByRole('row').filter({ hasText: mine.invoice })).toContainText('E2E Cashier');
        await expect(admin.getByRole('row').filter({ hasText: theirs.invoice })).toContainText('E2E Super Admin');
    });
});
