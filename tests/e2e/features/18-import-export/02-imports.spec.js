import { readFile, writeFile } from 'node:fs/promises';
import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';
import { xlsxText } from '../../support/xlsx.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('imports', () => {
    test.setTimeout(240_000);

    test('a CSV of customers is imported and shows in the export', async ({ page, arrange, uniq }, testInfo) => {
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

    test('the exported product workbook can be imported again without duplicating products', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        await signIn(page, email);
        await page.goto('/dashboard/products');

        // The page shows no message after a product import, so compare the export before and after instead.
        const exportFile = async () => {
            const [download] = await Promise.all([page.waitForEvent('download'), page.locator('a[href$="/export/products"]').click()]);
            return download.path();
        };
        const rows = async (file) => (xlsxText(await readFile(file)).match(/<row /g) ?? []).length;

        const file = await exportFile();
        const before = await rows(file);
        expect(before).toBeGreaterThan(1);
        const done = page.waitForResponse((response) => response.url().endsWith('/import/products') && response.request().method() === 'POST');
        await page.locator('#import-products-input').setInputFiles(file);
        expect((await done).status()).toBeLessThan(400);
        await page.waitForLoadState('networkidle');
        expect(await rows(await exportFile())).toBe(before);
    });
});
