import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addToCart, chooseCustomer, finish, increment, openPos } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

// The split panel and its two tender rows ("Split Pembayaran" is the panel's title).
const splitPanel = (page) => page.getByText('Split Pembayaran', { exact: true }).locator('xpath=ancestor::div[contains(@class,"space-y-3")][1]');
const tenderRow = (page, index) => splitPanel(page).locator(':scope > div.space-y-2').nth(index);

// A sale of 4 x 10.000 in the cart with a customer chosen, split panel open.
async function readyToSplit(page, product, customer) {
    await openPos(page);
    await addToCart(page, product.title);
    for (const qty of [2, 3, 4]) {
        await increment(page);
        await expect(page.getByText(`Rp 10.000 × ${qty}`)).toBeVisible();
    }
    await chooseCustomer(page, customer.name);
    await page.getByRole('button', { name: 'Split Pembayaran (2 metode)' }).click();
    await expect(splitPanel(page)).toBeVisible();
}

test.describe('POS checkout: split tender', () => {
    test.describe.configure({ timeout: 300_000 });

    test('cash plus transfer pays one sale in two parts', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('split'), stock: 10 });
        const customer = arrange('pos.customer', { name: uniq('Agus') });
        const bank = arrange('pos.bank-account', { bank: uniq('BNI') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await readyToSplit(page, product, customer);
        await expect(splitPanel(page)).toContainText('Sisa Rp 40.000');

        // Part one: cash, 15.000 due, 20.000 handed over. The first row starts as cash.
        await tenderRow(page, 0).getByPlaceholder('Nominal').fill('15000');
        await tenderRow(page, 0).getByPlaceholder('Uang diterima (opsional)').fill('20000');
        await expect(splitPanel(page)).toContainText('Sisa Rp 25.000');

        // A second method is required: one tender alone is refused.
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect(page.getByText('Split pembayaran memerlukan 2 metode')).toBeVisible();

        // With the second part started but short, the missing amount is named.
        await tenderRow(page, 1).locator('select').selectOption('bank_transfer');
        await tenderRow(page, 1).getByPlaceholder('Nominal').fill('10000');
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect(page.getByText('Kurang Rp 15.000 untuk melengkapi pembayaran')).toBeVisible();
        await expect(page).toHaveURL(/\/dashboard\/transactions$/);

        // Part two: the transfer covers the remaining 25.000; an account must be picked for it.
        await tenderRow(page, 1).getByPlaceholder('Nominal').fill('25000');
        await expect(splitPanel(page)).toContainText('Lengkap');
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect(page.getByText('Pilih rekening bank tujuan untuk tender transfer')).toBeVisible();

        await tenderRow(page, 1).locator('select').nth(1).selectOption({ label: `${bank.bank} - ${bank.account_number}` });
        await finish(page);

        // Receipt: split in two tenders. A split transfer counts as received at once (unlike a single transfer,
        // which waits for a manager's confirmation, see 01-bank-transfer and question #153), so the sale is paid.
        await expect(page.getByText('Split Pembayaran', { exact: true })).toBeVisible();
        await expect(page.getByText('2 tender')).toBeVisible();
        await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
    });

    test('paying more than the total in the two parts is refused', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('split-over'), stock: 10 });
        const customer = arrange('pos.customer', { name: uniq('Lia') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await readyToSplit(page, product, customer);
        await tenderRow(page, 0).getByPlaceholder('Nominal').fill('30000');
        await tenderRow(page, 1).locator('select').selectOption('midtrans');
        await tenderRow(page, 1).getByPlaceholder('Nominal').fill('20000');
        await expect(splitPanel(page)).not.toContainText('Lengkap');

        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect(page.getByText('Kelebihan Rp 10.000 dari total')).toBeVisible();
        await expect(page).toHaveURL(/\/dashboard\/transactions$/);
    });

    test('cash received below the cash part is refused, and cancelling the split returns to one method', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('split-short'), stock: 10 });
        const customer = arrange('pos.customer', { name: uniq('Mira') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await readyToSplit(page, product, customer);
        await tenderRow(page, 0).getByPlaceholder('Nominal').fill('20000');
        await tenderRow(page, 0).getByPlaceholder('Uang diterima (opsional)').fill('10000');
        await tenderRow(page, 1).locator('select').selectOption('xendit');
        await tenderRow(page, 1).getByPlaceholder('Nominal').fill('20000');
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect(page.getByText('Uang tunai split kurang dari nominal tender')).toBeVisible();

        await page.getByRole('button', { name: 'Batalkan Split Pembayaran' }).click();
        await expect(splitPanel(page)).toHaveCount(0);
        await expect(page.getByText('Pembayaran Tunai')).toBeVisible();
    });
});
