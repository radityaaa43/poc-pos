import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { signIn } from '../../support/platform.js';

const manager = roles.find((role) => role.key === 'manager');

// A direct POST with the page's session, as a script or a stale tab would send it.
const post = (page, path) => page.evaluate(async (url) => {
    const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1]);
    const response = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } });

    return response.status;
}, path);

test.describe('32-kitchen-display', () => {
    test.describe.configure({ timeout: 60_000 }); // two users, two contexts and two sales in one walk
    test('a role that may only prepare sees no Serahkan and a direct POST is refused; a hand-over-only role sees Serahkan but no prepare steps', { tag: ['@REQ-003-AC-10'] }, async ({ page, browser, asRole, arrange, uniq }) => {
        // Own users: no shared role holds exactly one of the two permissions.
        const cook = arrange('dine.user', { email: `${uniq('juru')}@e2e.test`, permissions: 'dashboard-access,dine-orders-prepare' });
        const waiter = arrange('dine.user', { email: `${uniq('antar')}@e2e.test`, permissions: 'dashboard-access,dine-orders-handover' });
        const prepared = arrange('kitchen-sale', { prefix: uniq('Masak') });
        const ready = arrange('kitchen-sale', { prefix: uniq('Antar') });

        // The prepare-only user takes its item to Siap, and is offered nothing after that.
        await signIn(page, cook.email);
        await page.goto('/dashboard/kitchen');
        const card = page.locator('article', { hasText: prepared.invoice });
        const item = card.getByRole('listitem');
        await item.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(item).toContainText('Sedang dibuat');
        await item.getByRole('button', { name: 'Siap', exact: true }).click();
        await expect(item.getByRole('button')).toHaveCount(0);
        await expect(item).toContainText('Siap');
        expect(await post(page, `/dashboard/kitchen/orders/${prepared.transaction_id}/hand-over-all`)).toBe(403);
        await page.reload();
        await expect(page.locator('article', { hasText: prepared.invoice })).toContainText('Siap');

        // A manager gets the other order to Siap; the hand-over-only user can serve it but never start or ready anything.
        const lead = await asRole(manager);
        await lead.goto('/dashboard/kitchen');
        const leadItem = lead.locator('article', { hasText: ready.invoice }).getByRole('listitem');
        await leadItem.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(leadItem).toContainText('Sedang dibuat');
        await leadItem.getByRole('button', { name: 'Siap', exact: true }).click();
        await expect(leadItem.getByRole('button', { name: 'Serahkan', exact: true })).toBeVisible();

        const waiterContext = await browser.newContext({ locale: 'id-ID', serviceWorkers: 'block' });
        const waiterPage = await waiterContext.newPage();
        await signIn(waiterPage, waiter.email);
        await waiterPage.goto('/dashboard/kitchen');
        const waiterCard = waiterPage.locator('article', { hasText: ready.invoice });
        await expect(waiterCard).toContainText('Siap');
        await expect(waiterCard.getByRole('button', { name: 'Serahkan', exact: true })).toBeVisible();
        await expect(waiterCard.getByRole('button', { name: 'Serahkan semua', exact: true })).toBeVisible();
        await expect(waiterCard.getByRole('button', { name: /Mulai buat|Siap|Semua siap/ })).toHaveCount(0);
        // The prepare-only user's ready item is also on this screen, and the waiter can serve it too.
        await expect(waiterPage.locator('article', { hasText: prepared.invoice }).getByRole('button', { name: 'Serahkan', exact: true })).toBeVisible();
        await waiterCard.getByRole('button', { name: 'Serahkan', exact: true }).click();
        await expect(waiterCard).toHaveCount(0);
        await waiterContext.close();
    });
});
