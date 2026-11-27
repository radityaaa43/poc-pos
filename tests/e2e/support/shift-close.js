// Shared setup of features/25-cashier-shifts/06..10: closing a shift that does or does not hold work.
import { test, expect as baseExpect } from './fixtures.js';
import { roles } from './roles.js';

export { test };
// Pages are slow when several agents share one machine: wait longer than the 15 s default.
export const expect = baseExpect.configure({ timeout: 30_000 });
export const cashier = roles.find((role) => role.key === 'cashier');

export const describeShiftClose = (steps) => test.describe('25-cashier-shifts', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });
    steps();
});

async function finalize(page, cash) {
    await page.getByRole('spinbutton').fill(cash);
    await page.getByRole('button', { name: 'Finalisasi Closing' }).click();
}

// On an open shift's page: closing is refused with the reason, and the shift stays open.
export async function closeRefused(page, cash) {
    await finalize(page, cash);
    await expect(page.getByRole('alert').filter({ hasText: 'Shift tidak bisa ditutup: masih ada item belum diserahkan atau tagihan belum lunas.' })).toBeVisible();
    await expect(page.getByText('Shift Aktif', { exact: true })).toBeVisible();
}

// A shift nothing holds: no list is shown, and closing with the expected 100.000 goes through.
export async function closeUnheld(page, shiftId) {
    await page.goto(`/dashboard/cashier-shifts/${shiftId}`);
    await expect(page.getByText('Shift Aktif', { exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Belum selesai' })).toHaveCount(0);
    await finalize(page, '100000');
    await expect(page.getByText('Shift Closed', { exact: true })).toBeVisible();
}
