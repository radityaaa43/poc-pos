import { test, expect } from '../../support/fixtures.js';
import { rupiah, total } from '../../support/pos.js';
import { readQueue } from '../../support/offline-pos.js';
import { amount, openBill, orderType, resetBillRules } from './bill.js';

test.describe('bill totals: offline order type', () => {
    test.describe.configure({ timeout: 240_000 });
    resetBillRules();

    // REQ-010 BR-3: the screen recomputes from the cached rules; the server computes the stored total at sync.
    test('offline, the order type stays selectable and the total follows it', async ({ arrange, asRole, uniq }) => {
        const page = await openBill({ arrange, asRole, uniq }, { service_charge_rate: 5 }, 11);
        await orderType(page, 'Bawa Pulang');
        await expect(total(page)).toHaveText(rupiah(22200));

        await page.context().setOffline(true);
        await orderType(page, 'Di Tempat');
        await expect(amount(page, 'Service charge')).toHaveText(`+${rupiah(1000)}`);
        await expect(total(page)).toHaveText(rupiah(23310));

        await page.getByRole('button', { name: 'Uang pas' }).click();
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect.poll(() => readQueue(page)).toHaveLength(1);
        const [queued] = await readQueue(page);
        await page.context().setOffline(false);
        await expect.poll(() => readQueue(page)).toHaveLength(0);

        expect(arrange('billsettings.sale', { uuid: queued.client_uuid }))
            .toEqual({ order_type: 'in_store', service_charge: 1000, tax_total: 2310, grand_total: 23310 });
    });
});
