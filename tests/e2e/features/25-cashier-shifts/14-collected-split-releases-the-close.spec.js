import { cashier, closeRefused, describeShiftClose, expect, test } from '../../support/shift-close.js';

// #530 BR-3: a partly paid split holds the close, and once its rest is collected the close goes through.
describeShiftClose(() => {
    test('collecting the rest of a split releases the shift close', { tag: ['@BR-3'] }, async ({ asRole, arrange }) => {
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        const split = arrange('posretry.split-failed-gateway');
        const page = await asRole(cashier);

        // Before the rest is collected the split holds the close.
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(page.getByRole('region', { name: 'Belum selesai' }).getByRole('listitem')).toContainText(split.invoice);
        await closeRefused(page, '105000');

        // Collect the rest from the POS Belum Lunas row, in cash.
        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Belum Lunas/ }).click();
        await page.getByRole('link', { name: 'Bayar sisa' }).click();
        await page.getByRole('button', { name: 'Bayar dengan cara lain' }).click();
        await page.getByLabel('Metode pembayaran').selectOption('cash');
        await page.getByLabel('Uang diterima').fill('15000');
        await page.getByRole('button', { name: 'Bayar', exact: true }).click();
        await expect(page.getByText('Lunas', { exact: true })).toBeVisible();

        // The sale no longer holds the close: opening 100.000 + 5.000 paid + 15.000 rest = 120.000.
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await page.getByRole('spinbutton').fill('120000');
        await page.getByRole('button', { name: 'Finalisasi Closing' }).click();
        await expect(page.getByText('Shift Closed', { exact: true })).toBeVisible();
    });
});
