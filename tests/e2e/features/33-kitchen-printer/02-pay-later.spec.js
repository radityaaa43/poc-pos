import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintDevice } from '../../support/kitchen.js';
import { fakePrinter, asText } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a pay-later order prints BELUM LUNAS, a paid one does not', { tag: ['@REQ-004-AC-02'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page);
        await setUpPrintDevice(page, uniq('Dapur'));

        const paid = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        const later = arrange('kitchen-sale', { prefix: uniq('Ctk'), pay_later: 1 });

        await expect.poll(() => sentWith(page, paid.invoice), { timeout: 10_000 }).toBeDefined();
        await expect.poll(() => sentWith(page, later.invoice), { timeout: 10_000 }).toBeDefined();

        expect(asText(await sentWith(page, later.invoice))).toContain('BELUM LUNAS');
        expect(asText(await sentWith(page, paid.invoice))).not.toContain('BELUM LUNAS');
    });
});
