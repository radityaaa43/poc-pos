import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');

test.describe('27-sales-returns', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('a draft needs an item and a reason, and a return cannot be completed without a shift', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cashier: 'manager@e2e.test', cash: 100000 });
        const sale = arrange('posops.sale', { cashier: 'manager@e2e.test', qty: 2, left: 8, customer: uniq('Siti') });
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await page.getByRole('link', { name: 'Retur', exact: true }).click();

        // No quantity chosen.
        await page.getByRole('button', { name: 'Buat Draft' }).click();
        await expect(page.getByText('Pilih minimal satu item retur dengan qty lebih dari 0.')).toBeVisible();

        // A quantity without a reason.
        await page.getByRole('spinbutton').fill('1');
        await page.getByRole('button', { name: 'Buat Draft' }).click();
        await expect(page.getByText('Alasan retur wajib diisi untuk setiap item yang diretur.')).toBeVisible();

        await page.getByPlaceholder('Alasan retur').fill('Salah beli');
        await page.getByRole('button', { name: 'Buat Draft' }).click();
        await expect(page.getByText('Draft', { exact: true })).toBeVisible();

        // The manager closes the shift elsewhere: completing the draft is refused and it stays a draft.
        arrange('posops.no-shift', { cashier: 'manager@e2e.test' });
        const refused = page.waitForResponse((response) => response.url().endsWith('/complete'));
        await page.getByRole('button', { name: 'Selesaikan Retur' }).click();
        await refused;

        // Read it back from the server: the return is still a draft.
        await page.reload();
        await expect(page.getByText('Draft', { exact: true })).toBeVisible();
        await expect(page.getByText('Completed', { exact: true })).toHaveCount(0);
    });
});
