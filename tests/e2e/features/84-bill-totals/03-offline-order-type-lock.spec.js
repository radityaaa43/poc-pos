import { test, expect } from '../../support/fixtures.js';
import { rupiah, total } from '../../support/pos.js';
import { openBill, resetBillRules } from './bill.js';

test.describe('bill totals: offline order type', () => {
    test.describe.configure({ timeout: 240_000 });
    resetBillRules();

    // REQ-010 BR-5: offline the screen cannot recompute the total, so the type chosen before stays.
    test('offline at an outlet with a service charge, the order type cannot be changed and the screen says why', async ({ arrange, asRole, uniq }) => {
        const page = await openBill({ arrange, asRole, uniq }, { service_charge_rate: 5 });
        const takeaway = page.getByRole('button', { name: 'Bawa Pulang', exact: true });
        const reason = page.getByText('Tipe pesanan tidak bisa diubah saat offline di outlet ini');
        await expect(takeaway).toBeEnabled();

        await page.context().setOffline(true);
        await expect(takeaway).toBeDisabled();
        await expect(reason).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(21000));

        await page.context().setOffline(false);
        await expect(takeaway).toBeEnabled();
        await expect(reason).toBeHidden();
    });
});
