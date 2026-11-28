import { test, expect } from '../../support/fixtures.js';
import { finish, rupiah, total } from '../../support/pos.js';
import { amount, openBill, resetBillRules } from './bill.js';

// 2 x Rp 10.000 of a product with 11 % PPN, dine-in with a 5 % service charge.
const cases = [
    {
        criterion: 'AC-06', title: 'a PB1 outlet taxes subtotal plus service charge and drops the product PPN',
        rules: { service_charge_rate: 5, tax_kind: 'pb1', pb1_rate: 10 },
        label: 'PB1 10%', receiptLabel: 'PB1 10%', tax: 2100, grand: 23100, absent: /^PPN/,
    },
    {
        criterion: 'AC-07', title: 'a PPN outlet keeps the product PPN and taxes the service charge at its rate',
        rules: { service_charge_rate: 5 },
        label: 'PPN', receiptLabel: 'PPN 11%', tax: 2310, grand: 23310, absent: /^PB1/,
    },
];

test.describe('bill totals: tax kinds', () => {
    test.describe.configure({ timeout: 240_000 });
    resetBillRules();

    for (const bill of cases) {
        test(bill.title, { tag: [`@REQ-010-${bill.criterion}`] }, async ({ arrange, asRole, uniq }) => {
            const page = await openBill({ arrange, asRole, uniq }, bill.rules, 11);
            await expect(amount(page, 'Service charge')).toHaveText(`+${rupiah(1000)}`);
            await expect(amount(page, bill.label)).toHaveText(`+${rupiah(bill.tax)}`);
            await expect(page.getByText(bill.absent)).toHaveCount(0);
            await expect(total(page)).toHaveText(rupiah(bill.grand));

            await page.getByRole('button', { name: 'Uang pas' }).click();
            await finish(page);
            await expect(amount(page, bill.receiptLabel)).toHaveText(`+ ${rupiah(bill.tax)}`);
            await expect(page.getByText('Total', { exact: true }).locator('xpath=..')).toContainText(rupiah(bill.grand));
        });
    }
});
