import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

// The value under a "Total / Terbayar / Sisa" label of the receivable header.
const figure = (page, label) => page.getByText(label, { exact: true }).first().locator('xpath=following-sibling::p');

test.describe('29-receivables', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('a cashier records a partial and then the final payment', async ({ asRole, arrange, uniq }) => {
        const receivable = arrange('posops.receivable', { total: 100000, customer: uniq('Farah') });
        const page = await asRole(cashier);

        await page.goto(`/dashboard/receivables/${receivable.receivable_id}`);
        await expect(page.getByText('Belum ada pembayaran.')).toBeVisible();
        await page.getByRole('button', { name: 'Tambah Pembayaran' }).click();

        // More than what is left is refused.
        await page.getByRole('spinbutton').fill('150000');
        await page.getByRole('button', { name: 'Simpan Pembayaran' }).click();
        await expect(page.getByText('Nominal melebihi sisa piutang.')).toBeVisible();

        await page.getByRole('spinbutton').fill('40000');
        await page.getByPlaceholder('Catatan pembayaran').fill('Cicilan pertama');
        await page.getByRole('button', { name: 'Simpan Pembayaran' }).click();

        await expect(figure(page, 'Terbayar')).toHaveText(/Rp\s*40\.000/);
        await expect(figure(page, 'Sisa')).toHaveText(/Rp\s*60\.000/);
        await expect(page.getByText('Parsial').first()).toBeVisible();
        await expect(page.getByText('Cicilan pertama')).toBeVisible();
        await expect(page.getByText(/cash/).first()).toBeVisible();

        await page.getByRole('button', { name: 'Tambah Pembayaran' }).click();
        await page.getByRole('spinbutton').fill('60000');
        await page.getByRole('button', { name: 'Simpan Pembayaran' }).click();

        await expect(figure(page, 'Sisa')).toHaveText(/Rp\s*0/);
        await expect(page.getByText('Lunas').first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Tambah Pembayaran' })).toHaveCount(0);

        // Side effects: the list and the sale itself now read as paid.
        await page.goto(`/dashboard/receivables?invoice=${receivable.invoice}`);
        await expect(page.getByRole('link').filter({ hasText: receivable.invoice })).toContainText('Lunas');
        await page.goto(`/dashboard/transactions/history?invoice=${receivable.invoice}`);
        await expect(page.getByRole('row').filter({ hasText: receivable.invoice })).toContainText('Lunas');
    });
});
