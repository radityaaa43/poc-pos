import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const admin = roles.find((role) => role.key === 'super-admin');

async function openDraft(page, documentNumber) {
    await page.goto(`/dashboard/purchase-orders?search=${documentNumber}`);
    await page.getByRole('row', { name: new RegExp(documentNumber) }).getByRole('link').first().click();
    await expect(page.getByRole('heading', { name: documentNumber })).toBeVisible();
}

test.describe('58-reorder-drafts', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    // `reorder:generate` runs nightly and has no screen, so the arrange step runs the same service for the products
    // this step created, and the browser checks the draft orders it made.
    test('central-and-outlet: each warehouse drafts only the materials it buys, in whole kg', {
        tag: ['@REQ-002-AC-16'],
    }, async ({ asRole, arrange }) => {
        const { central_po, outlet_po, coffee, ice, plain } = arrange('purchasing.reorder', {});
        const page = await asRole(admin);

        // Central warehouse: the coffee it buys (2.300 g short of 10.000 g -> 3 kg at Rp 250.000 per kg), no ice.
        await openDraft(page, central_po);
        await expect(page.getByText('Draft', { exact: true })).toBeVisible();
        const coffeeLine = page.getByRole('row', { name: new RegExp(coffee) });
        await expect(coffeeLine).toContainText('3 kg');
        await expect(coffeeLine).toContainText('= 3.000 g');
        await expect(coffeeLine).toContainText(/Rp\s*250\.000\s*\/ kg/);
        await expect(page.getByRole('row', { name: new RegExp(ice) })).toHaveCount(0);

        // A product that is not a raw material is ordered as before: 50 - 4 = 46 in its own unit at its buy price.
        const plainLine = page.getByRole('row', { name: new RegExp(plain) });
        await expect(plainLine).toContainText('46');
        await expect(plainLine).toContainText(/Rp\s*500/);

        // The outlet's warehouse: the ice it buys, no coffee.
        await openDraft(page, outlet_po);
        const iceLine = page.getByRole('row', { name: new RegExp(ice) });
        await expect(iceLine).toContainText('3 kg');
        await expect(iceLine).toContainText(/Rp\s*20\.000\s*\/ kg/);
        await expect(page.getByRole('row', { name: new RegExp(coffee) })).toHaveCount(0);
        await expect(page.getByRole('row', { name: new RegExp(plain) })).toContainText('46');
    });
});
