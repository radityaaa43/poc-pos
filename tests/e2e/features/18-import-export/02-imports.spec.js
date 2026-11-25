import { readFile, writeFile } from 'node:fs/promises';
import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';
import { xlsxText } from '../../support/xlsx.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

// Both imports are broken today, so both steps are fixme with a bug issue; each needs its cleanup once fixed.
test.describe('imports', () => {
    test.setTimeout(240_000);

    // Bug #155: a plain CSV is refused ("must be a file of type: xlsx, xls, csv") and the page shows nothing.
    test.fixme('a CSV of customers is imported and shows in the export', async ({ page, arrange, uniq }, testInfo) => {
        const { email } = arrange('platform.user');
        const name = uniq('Pelanggan Impor');
        const csv = testInfo.outputPath('customer.csv');
        await writeFile(csv, `nama,telepon,alamat\n${name},081200000001,Jl. Impor 1`);

        try {
            await signIn(page, email);
            await page.goto('/dashboard/customers');
            await page.locator('#import-customers-input').setInputFiles(csv);

            await page.goto('/dashboard/customers');
            const [download] = await Promise.all([page.waitForEvent('download'), page.locator('a[href$="/export/customers"]').click()]);
            expect(xlsxText(await readFile(await download.path()))).toContain(name);
        } finally {
            arrange('platform.drop-customer', { name });
        }
    });

    // Bug #154: importing any product answers HTTP 500 (duplicate primary key), even with the file the app exported.
    test.fixme('the exported product workbook can be imported again without duplicating products', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        await signIn(page, email);
        await page.goto('/dashboard/products');

        const [download] = await Promise.all([page.waitForEvent('download'), page.locator('a[href$="/export/products"]').click()]);
        const done = page.waitForResponse((response) => response.url().endsWith('/import/products') && response.request().method() === 'POST');
        await page.locator('#import-products-input').setInputFiles(await download.path());
        expect((await done).status()).toBeLessThan(400);
        await expect(page.getByText(/Import selesai/)).toBeVisible();
    });
});
