import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { rupiah } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');
const manager = roles.find((role) => role.key === 'manager');

// A guest or non-credit Bayar Belakangan bill has no receivable: it waits in the POS "Belum Lunas" section until the
// buyer pays, in full, with any method (REQ-003 #278). `posops.sale` with guest=1 arranges one the way checkout leaves it.
test.describe('POS: settle a guest pay-later bill', () => {
    test.describe.configure({ timeout: 240_000 });

    const openList = async (page) => {
        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Belum Lunas/ }).click();
    };

    test('@REQ-003-AC-17 the bill is listed with its note, amount and age, and paying it in cash settles it without a receivable', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cashier: cashier.email, cash: 100000 });
        const note = uniq('Meja luar');
        const bill = arrange('posops.sale', { method: 'pay_later', guest: 1, note, qty: 2, left: 8, title: uniq('Soto') });
        const page = await asRole(cashier);

        await openList(page);
        const row = page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: note });
        await expect(row).toContainText(bill.invoice);
        await expect(row).toContainText(rupiah(20000));
        await expect(row).toContainText(/\d+ (mnt|j)/); // the age

        await row.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('20000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${bill.invoice}/print`));

        expect(arrange('paylater.sale-state', { invoice: bill.invoice })).toMatchObject({ payment_status: 'paid', receivables: 0, is_pay_later: true });
        await openList(page).catch(() => {});
        await expect(page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: note })).toHaveCount(0);
    });

    test('@REQ-003-AC-17 paying less than the total is refused and the bill stays', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cashier: cashier.email, cash: 100000 });
        const note = uniq('Meja');
        const bill = arrange('posops.sale', { method: 'pay_later', guest: 1, note, qty: 2, left: 8, title: uniq('Mie') });
        const page = await asRole(cashier);

        await openList(page);
        const row = page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: note });
        await row.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('5000');
        // The modal refuses an amount below the total before sending anything (the server refuses it too: PHPUnit
        // test_partial_tender_is_refused), so the dialog stays open and nothing is saved.
        let sent = false;
        page.on('request', (request) => { if (request.url().includes('/settle')) sent = true; });
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForTimeout(1500);
        await expect(dialog).toBeVisible();
        expect(sent).toBe(false);

        expect(arrange('paylater.sale-state', { invoice: bill.invoice })).toMatchObject({ payment_status: 'unpaid', tenders: 0 });
    });

    test('@REQ-003-AC-17 settling counts in the shift it is taken in and sends nothing more to the kitchen', async ({ asRole, arrange, uniq }) => {
        // Sold in the cashier's shift; paid by the manager from their own shift of the same outlet.
        arrange('open-shift', { cashier: cashier.email, cash: 100000 });
        const note = uniq('Meja');
        const dish = arrange('posops.sale', { method: 'pay_later', guest: 1, note, qty: 1, left: 9, title: uniq('Rujak') });
        arrange('kitchen.flag', { product_id: dish.product_id });
        const before = arrange('paylater.sale-state', { invoice: dish.invoice });
        const { shift_id } = arrange('open-shift', { cashier: manager.email, cash: 50000 });
        const page = await asRole(manager);

        await openList(page);
        const row = page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: note });
        await row.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('10000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${dish.invoice}/print`));

        const after = arrange('paylater.sale-state', { invoice: dish.invoice });
        expect(after.payment_status).toBe('paid');
        expect(after.cashier_shift_id).toBe(shift_id);
        expect(after.cashier_shift_id).not.toBe(before.cashier_shift_id);
        // The flag was set after the arranged sale, so no kitchen item existed; settling records none either.
        expect(arrange('kitchen.items', { invoice: dish.invoice })).toMatchObject({ count: 0 });
    });

    test('@REQ-003-AC-17 two cashiers pay the same bill: the first wins and the second is told it is paid', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cashier: cashier.email, cash: 100000 });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });
        const note = uniq('Meja');
        const bill = arrange('posops.sale', { method: 'pay_later', guest: 1, note, qty: 1, left: 9, title: uniq('Teh') });
        const first = await asRole(cashier);
        const second = await asRole(manager);

        await openList(first);
        await openList(second);
        const pay = async (page) => {
            const row = page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: note });
            await row.getByRole('button', { name: 'Bayar' }).click();
            const dialog = page.getByRole('dialog');
            await dialog.getByLabel('Uang diterima').fill('10000');
            return dialog;
        };
        const firstDialog = await pay(first);
        const secondDialog = await pay(second);

        await firstDialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await first.waitForURL(new RegExp(`/transactions/${bill.invoice}/print`));
        await secondDialog.getByRole('button', { name: 'Bayar', exact: true }).click();

        await expect(second.getByText('Tagihan ini sudah dilunasi.')).toBeVisible();
        expect(arrange('paylater.sale-state', { invoice: bill.invoice })).toMatchObject({ payment_status: 'paid', tenders: 1 });
    });

    test('@REQ-003-AC-17 a fully returned bill leaves the list, and a partly returned one shows the remaining amount', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cashier: cashier.email, cash: 100000 });
        const whole = uniq('Penuh');
        const partial = uniq('Sebagian');
        const wholeBill = arrange('posops.sale', { method: 'pay_later', guest: 1, note: whole, qty: 1, left: 9, title: uniq('A') });
        const partialBill = arrange('posops.sale', { method: 'pay_later', guest: 1, note: partial, qty: 2, left: 8, title: uniq('B') });
        arrange('paylater.return', { invoice: wholeBill.invoice });
        arrange('paylater.return', { invoice: partialBill.invoice, qty: 1 });
        const page = await asRole(cashier);

        await openList(page);
        const list = page.getByTestId('open-bills');
        await expect(list.getByRole('listitem').filter({ hasText: whole })).toHaveCount(0);
        const row = list.getByRole('listitem').filter({ hasText: partial });
        await expect(row).toContainText(rupiah(10000)); // 20.000 sold, 10.000 returned: the remaining amount
        await expect(row).not.toContainText(rupiah(20000));

        // The modal asks for the remaining amount, and paying exactly that settles the bill (BR-9).
        await row.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toContainText(rupiah(10000));
        await dialog.getByLabel('Uang diterima').fill('10000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${partialBill.invoice}/print`));
        expect(arrange('paylater.sale-state', { invoice: partialBill.invoice })).toMatchObject({ payment_status: 'paid', tenders: 1 });
    });

    test('@REQ-003-AC-17 without an open shift the bill cannot be settled and stays listed', async ({ asRole, arrange, uniq }) => {
        const note = uniq('Meja');
        arrange('open-shift', { cashier: cashier.email, cash: 100000 });
        const bill = arrange('posops.sale', { method: 'pay_later', guest: 1, note, qty: 1, left: 9, title: uniq('Kopi') });
        const page = await asRole(cashier);
        await openList(page);
        const row = page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: note });
        await row.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('10000');

        arrange('dine.close-shifts', { cashier: cashier.email }); // the shift is closed under the open dialog
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();

        await expect(page.getByText('Shift kasir belum dibuka.')).toBeVisible();
        expect(arrange('paylater.sale-state', { invoice: bill.invoice })).toMatchObject({ payment_status: 'unpaid', tenders: 0 });
    });
});
