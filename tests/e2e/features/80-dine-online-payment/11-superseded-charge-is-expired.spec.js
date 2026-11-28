import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';
import { guestStartsPaying } from '../../support/dine-bill.js';

// Pages are slow when several agents share one machine, and this step chains pages: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

// Issue #359: a guest charge the cashier supersedes is expired at the gateway, queued after her payment commits. The e2e
// queue is `sync` and the gateway is faked (E2eServiceProvider), so the expiry has run by the time the receipt shows.
test.describe('80-dine-online-payment', () => {
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('the cashier takes cash while the guest is paying online: the guest charge is expired at the gateway', async ({ arrange, page }) => {
        const bill = await guestStartsPaying(page, arrange, { staff: '1' });
        await signIn(page, bill.cashier_email);

        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Belum Lunas/ }).click();
        await page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: bill.invoice }).getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill(String(bill.grand_total));
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${bill.invoice}/print`));

        const state = arrange('dine.order-state', { access_token: bill.access_token });
        expect(state).toMatchObject({ payment_status: 'paid', payment_method: 'cash', payment_attempt: 2 });
        expect(state.audit['payment.superseded_charge_expired']).toBe(1);
    });
});
