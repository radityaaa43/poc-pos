import { test, expect } from '../../support/fixtures.js';
import { login } from '../../support/login.js';
import { roles } from '../../support/roles.js';
import { addToCart, chooseCustomer, finish, openPos, rupiah, total } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');
const manager = roles.find((role) => role.key === 'manager');

// A sale reaches the kitchen only when it is paid, and only its lines that need preparation (REQ-003). There is no
// kitchen screen in this ticket, so each step reads the result back through the `kitchen.items` arrange scenario.
test.describe('32-kitchen-display: intake', () => {
    test.describe.configure({ timeout: 240_000 });

    test('@REQ-003-AC-02 @REQ-003-AC-05 a paid mixed cart sends only the flagged line to the kitchen, as accepted', async ({ asRole, arrange, uniq }) => {
        const dish = arrange('product', { title: uniq('Nasi'), stock: 10 });
        const water = arrange('product', { title: uniq('Air'), stock: 10 });
        const customer = arrange('pos.customer', { name: uniq('Hadi') }); // the Selesaikan button needs one (#133)
        arrange('kitchen.flag', { product_id: dish.product_id });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, dish.title);
        await addToCart(page, water.title);
        await chooseCustomer(page, customer.name);
        await expect(total(page)).toHaveText(rupiah(20000)); // "Uang pas" fills the total, so wait until it is final
        await page.getByRole('button', { name: 'Uang pas' }).click();
        const invoice = await finish(page);

        expect(arrange('kitchen.items', { invoice })).toEqual({ count: 1, product_ids: [dish.product_id], statuses: ['accepted'] });
    });

    test('@REQ-003-AC-02 a pending transfer stays out of the kitchen until it is confirmed paid', async ({ asRole, arrange, uniq, browser }) => {
        const dish = arrange('product', { title: uniq('Mie'), stock: 10 });
        arrange('kitchen.flag', { product_id: dish.product_id });
        const customer = arrange('pos.customer', { name: uniq('Dewi') });
        const bank = arrange('pos.bank-account', { bank: uniq('BCA') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, dish.title);
        await chooseCustomer(page, customer.name);
        await page.getByRole('button', { name: 'Transfer Bank' }).click();
        await page.getByRole('button', { name: new RegExp(bank.bank) }).click();
        const invoice = await finish(page);
        await expect(page.getByText('Menunggu', { exact: true })).toBeVisible();

        expect(arrange('kitchen.items', { invoice })).toMatchObject({ count: 0 });

        // A fresh manager login, as in 22-pos-checkout-payments/01: the password step-up must not leak into the saved session.
        const boss = await (await browser.newContext({ locale: 'id-ID', serviceWorkers: 'block' })).newPage();
        await login(boss, manager.email);
        await expect(boss).toHaveURL(/\/dashboard/);
        await boss.goto(`/dashboard/transactions/${invoice}/print`);
        await expect(boss.getByText('Menunggu', { exact: true })).toBeVisible();
        await boss.getByRole('button', { name: 'Konfirmasi Bayar' }).click();
        await boss.getByRole('button', { name: 'Konfirmasi Lunas' }).click();
        const stepUp = boss.getByRole('heading', { name: 'Konfirmasi Password' });
        await expect(stepUp.or(boss.getByText('Lunas', { exact: true }))).toBeVisible();
        if (await stepUp.isVisible()) {
            await boss.getByPlaceholder('Masukkan password Anda').fill('password');
            await boss.getByRole('button', { name: 'Lanjutkan' }).click();
            await expect(stepUp).toHaveCount(0);
            await boss.goto(`/dashboard/transactions/${invoice}/print`);
            await boss.getByRole('button', { name: 'Konfirmasi Bayar' }).click();
            await boss.getByRole('button', { name: 'Konfirmasi Lunas' }).click();
        }
        await expect(boss.getByText('Lunas', { exact: true })).toBeVisible();

        expect(arrange('kitchen.items', { invoice })).toEqual({ count: 1, product_ids: [dish.product_id], statuses: ['accepted'] });
    });

    test('@REQ-003-AC-19 failed and expired payments send nothing to the kitchen, and paying the open bill later, even twice, sends it once', async ({ asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const dish = arrange('product', { title: uniq('Rujak'), stock: 5 });
        arrange('kitchen.flag', { product_id: dish.product_id });
        const { access_token } = arrange('dine.order', { token, product_id: dish.product_id, qty: 1 });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });
        const page = await asRole(manager);

        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        const { invoice } = arrange('dine.order-state', { access_token, product_id: dish.product_id });
        expect(arrange('kitchen.items', { invoice })).toMatchObject({ count: 0 });

        for (const status of ['failed', 'expired']) {
            arrange('kitchen.set-status', { invoice, status });
            expect(arrange('kitchen.items', { invoice })).toMatchObject({ count: 0 });
        }
        arrange('kitchen.set-status', { invoice, status: 'failed' }); // the open bill can be paid from a failed state

        await page.goto(`/dashboard/transactions/history?invoice=${invoice}`);
        const row = page.getByRole('row').filter({ hasText: invoice });
        await row.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('10000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${invoice}/print`));

        expect(arrange('kitchen.items', { invoice })).toEqual({ count: 1, product_ids: [dish.product_id], statuses: ['accepted'] });

        // Paid a second time (a retried webhook): still one item.
        arrange('kitchen.set-status', { invoice, status: 'pending' });
        arrange('kitchen.set-status', { invoice, status: 'paid' });
        expect(arrange('kitchen.items', { invoice })).toMatchObject({ count: 1 });
    });
});
