import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addToCart, chooseCustomer, finish, openPos, rupiah, total } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');
const manager = roles.find((role) => role.key === 'manager');

test.describe('POS checkout: bank transfer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a transfer needs an account, waits for payment, and a manager confirms it', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('transfer'), stock: 10 });
        const customer = arrange('pos.customer', { name: uniq('Dewi') });
        const bank = arrange('pos.bank-account', { bank: uniq('BCA') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title);
        await chooseCustomer(page, customer.name);

        // Transfer is offered because an active account exists; the accounts are listed to pick from.
        await page.getByRole('button', { name: 'Transfer Bank' }).click();
        await expect(page.getByText('Rekening Tujuan')).toBeVisible();
        await expect(page.getByRole('button', { name: new RegExp(bank.bank) })).toBeVisible();

        // Submitting without choosing one is refused before anything is saved.
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect(page.getByText('Pilih rekening bank tujuan')).toBeVisible();
        await expect(page).toHaveURL(/\/dashboard\/transactions$/);

        await page.getByRole('button', { name: new RegExp(bank.bank) }).click();
        const invoice = await finish(page);

        // The receipt tells the customer where to transfer and that payment is still awaited.
        await expect(page.getByText('Menunggu', { exact: true })).toBeVisible();
        await expect(page.getByText('Silakan Transfer ke Rekening')).toBeVisible();
        await expect(page.getByText(bank.bank, { exact: true })).toBeVisible();
        await expect(page.getByText(bank.account_number)).toBeVisible();
        await expect(page.getByText('PT Uji E2E')).toBeVisible();
        // A cashier cannot confirm the money arrived.
        await expect(page.getByRole('button', { name: 'Konfirmasi Bayar' })).toHaveCount(0);

        // A manager sees the same receipt with the confirm button, and confirming marks it paid.
        const boss = await asRole(manager);
        await boss.goto(`/dashboard/transactions/${invoice}/print`);
        await expect(boss.getByText('Menunggu', { exact: true })).toBeVisible();
        await boss.getByRole('button', { name: 'Konfirmasi Bayar' }).click();
        await boss.getByRole('button', { name: 'Konfirmasi Lunas' }).click();
        // Confirming is an admin action: the app asks for the password again (step-up), then the confirm goes through.
        await expect(boss.getByRole('heading', { name: 'Konfirmasi Password' })).toBeVisible();
        await boss.getByPlaceholder('Masukkan password Anda').fill('password');
        await boss.getByRole('button', { name: 'Lanjutkan' }).click();
        await expect(boss.getByRole('heading', { name: 'Konfirmasi Password' })).toHaveCount(0);
        await boss.goto(`/dashboard/transactions/${invoice}/print`);
        await boss.getByRole('button', { name: 'Konfirmasi Bayar' }).click();
        await boss.getByRole('button', { name: 'Konfirmasi Lunas' }).click();
        await expect(boss.getByText('Lunas', { exact: true })).toBeVisible();
        await expect(boss.getByRole('button', { name: 'Konfirmasi Bayar' })).toHaveCount(0);
    });

    test('without an active account the transfer method is not offered', async ({ asRole, arrange, uniq }) => {
        arrange('pos.no-bank-account');
        arrange('pos.open-shift', { cash: 100000 });
        const product = arrange('product', { title: uniq('no-bank'), stock: 10 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title);
        await expect(page.getByRole('button', { name: 'Tunai', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Transfer Bank' })).toHaveCount(0);
        await expect(total(page)).toHaveText(rupiah(10000));
    });
});
