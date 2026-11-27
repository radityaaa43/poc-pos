import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('25-cashier-shifts', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('the close is refused with the list while food is not handed over, and the shift stays open', { tag: ['@REQ-003-AC-12'] }, async ({ asRole, arrange, uniq }) => {
        // Opens the cashier's shift and sells one product that needs preparation, paid in cash.
        const sale = arrange('kitchen-sale', { prefix: uniq('Tahan') });
        const { shift_id } = arrange('shiftclose.state', { invoice: sale.invoice });
        const page = await asRole(cashier);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);

        const rows = page.getByRole('region', { name: 'Belum selesai' }).getByRole('listitem');
        await expect(rows).toHaveCount(1);
        await expect(rows).toContainText(sale.invoice);
        await expect(rows).toContainText(`${sale.flagged[0]} × 1`);
        await expect(rows).toContainText('Belum diserahkan');
        await expect(rows).toContainText(/Rp\s*10\.000/);
        // Without cashier-shifts-force-close there is no way to force it.
        await expect(page.getByLabel('Catatan tutup paksa')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Tutup paksa' })).toHaveCount(0);

        await page.getByRole('spinbutton').fill('110000');
        await page.getByRole('button', { name: 'Finalisasi Closing' }).click();
        await expect(page.getByRole('alert').filter({ hasText: 'Shift tidak bisa ditutup: masih ada item belum diserahkan atau tagihan belum lunas.' })).toBeVisible();
        await expect(page.getByText('Shift Aktif', { exact: true })).toBeVisible();

        const state = arrange('shiftclose.state', { shift_id });
        expect(state.status).toBe('open');
        expect(state.audit['cashier_shift.closed'] ?? 0).toBe(0);
        expect(arrange('kitchen.items', { invoice: sale.invoice }).statuses).toEqual(['accepted']);
    });
});
