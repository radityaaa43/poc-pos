import { test, expect } from '../../support/fixtures.js';
import { finish, rupiah, total } from '../../support/pos.js';
import { amount, openBill, orderType, resetBillRules } from './bill.js';

test.describe('bill totals: service charge', () => {
    test.describe.configure({ timeout: 240_000 });
    resetBillRules();

    test('a dine-in sale shows the service charge at checkout and on the receipt; takeaway and delivery are not charged', { tag: ['@REQ-010-AC-05'] }, async ({ arrange, asRole, uniq }) => {
        const page = await openBill({ arrange, asRole, uniq }, { service_charge_rate: 5 });
        await expect(amount(page, 'Service charge')).toHaveText(`+${rupiah(1000)}`);
        await expect(total(page)).toHaveText(rupiah(21000));

        // Back to Di Tempat in between, so each choice has to change the total.
        for (const other of ['Bawa Pulang', 'Diantar']) {
            await orderType(page, other);
            await expect(total(page)).toHaveText(rupiah(20000));
            await expect(page.getByText('Service charge', { exact: true })).toBeHidden();
            await orderType(page, 'Di Tempat');
            await expect(total(page)).toHaveText(rupiah(21000));
        }

        await page.getByRole('button', { name: 'Uang pas' }).click();
        await finish(page);
        await expect(amount(page, 'Service charge')).toHaveText(`+ ${rupiah(1000)}`);
        await expect(page.getByText('Total', { exact: true }).locator('xpath=..')).toContainText(rupiah(21000));
    });
});
