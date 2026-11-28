import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine, and these steps chain pages: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

// #532 BR-1, BR-2: a gateway claim its request never stored (no reference after two minutes) is paid from
// Transactions History with the same settle flow and refusals as the POS "Belum Lunas" list.
test.describe('80-dine-online-payment', () => {
    test.describe.configure({ timeout: 180_000 });
    test.use({ actionTimeout: 30_000 });

    const openBills = async (page) => {
        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Belum Lunas/ }).click();

        return page.getByTestId('open-bills');
    };

    // The cashier starts a QRIS charge on the bill (as the POS list does), then the request is simulated as dead
    // before it stored the charge: no reference, older than the two-minute claim window.
    const claimThenAbandon = async (page, arrange, invoice) => {
        const list = await openBills(page);
        await list.getByRole('listitem').filter({ hasText: invoice }).getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Metode pembayaran').selectOption('qris');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${invoice}/print`));
        arrange('paylater.abandon-claim', { invoice });
    };

    test('BR-1 an abandoned gateway claim offers Bayar in History and settles in cash', async ({ arrange, page }) => {
        arrange('open-shift', { cash: 100000 });
        const { invoice } = arrange('kitchen-sale', { pay_later: 1 });
        await signIn(page, cashier.email);

        await claimThenAbandon(page, arrange, invoice);

        await page.goto(`/dashboard/transactions/history?invoice=${invoice}`);
        const row = page.getByRole('row').filter({ hasText: invoice });
        await expect(row).toContainText('Belum Lunas');
        await row.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('10000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${invoice}/print`));

        expect(arrange('paylater.sale-state', { invoice })).toMatchObject({ payment_status: 'paid', is_pay_later: true });
        await page.goto(`/dashboard/transactions/history?invoice=${invoice}`);
        await expect(page.getByRole('row').filter({ hasText: invoice })).toContainText('Lunas');
    });

    test('BR-2 a sale paid from Belum Lunas first refuses the stale History Bayar', async ({ arrange, page, asRole }) => {
        arrange('open-shift', { cash: 100000 });
        const { invoice } = arrange('kitchen-sale', { pay_later: 1 });
        await signIn(page, cashier.email);
        await claimThenAbandon(page, arrange, invoice);

        // History offers Bayar; the dialog is left open while the bill is paid elsewhere.
        await page.goto(`/dashboard/transactions/history?invoice=${invoice}`);
        await page.getByRole('row').filter({ hasText: invoice }).getByRole('button', { name: 'Bayar' }).click();
        const stale = page.getByRole('dialog');
        await expect(stale).toBeVisible();

        // The same cashier pays it from the POS list in another tab (asRole keeps the Indonesian UI, like the page above).
        const till = await asRole(cashier);
        const list = await openBills(till);
        await list.getByRole('listitem').filter({ hasText: invoice }).getByRole('button', { name: 'Bayar' }).click();
        const dialog = till.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('10000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await till.waitForURL(new RegExp(`/transactions/${invoice}/print`));

        // The stale dialog sends its payment anyway: the server refuses it with the existing message and books nothing.
        await stale.getByLabel('Uang diterima').fill('10000');
        await stale.getByRole('button', { name: 'Bayar', exact: true }).click();
        await expect(page.getByText('Tagihan ini sudah dilunasi.')).toBeVisible();
        expect(arrange('paylater.sale-state', { invoice })).toMatchObject({ payment_status: 'paid' });
    });
});
