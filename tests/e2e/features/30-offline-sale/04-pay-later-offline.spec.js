import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { readQueue } from '../../support/offline-pos.js';
import { addToCart, openPos } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

// REQ-003 BR-8: Bayar Belakangan needs the server (it records a receivable and tells the kitchen), so offline the POS
// shows a notice instead of the toggle and nothing is queued. The browser blocks service workers (fixtures.js), so the
// POS is loaded online and the network is cut afterwards.
test.describe('offline sale: pay later', () => {
    test('offline, Bayar Belakangan is replaced by a notice and nothing is queued', async ({ asRole, arrange, uniq }) => {
        test.setTimeout(120_000);
        const product = arrange('product', { title: uniq('Kopi'), stock: 10 });
        arrange('paylater.setting', { enabled: 1 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title);
        await expect(page.getByText('Bayar Belakangan', { exact: true })).toBeVisible();

        await page.context().setOffline(true);

        await expect(page.getByText('Bayar belakangan tidak tersedia saat offline')).toBeVisible();
        await expect(page.getByRole('checkbox')).toHaveCount(0);
        expect(await readQueue(page)).toEqual([]);

        await page.context().setOffline(false);
    });
});
