import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('32-kitchen-display', () => {
    test('a QR order and a POS sale appear in one queue, oldest first', { tag: ['@REQ-003-AC-06'] }, async ({ asRole, arrange, uniq }) => {
        // The guest order is paid first, so it is the older one.
        const qr = arrange('kitchen-sale', { prefix: uniq('QR'), dine: 1 });
        const pos = arrange('kitchen-sale', { prefix: uniq('POS'), plain: 1 });

        const page = await asRole(cashier);
        await page.goto('/dashboard/kitchen');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Layar Dapur', exact: true })).toBeVisible();

        const qrCard = page.locator('article', { hasText: qr.table_name });
        await expect(qrCard.getByRole('heading', { name: qr.table_name, exact: true })).toBeVisible();
        await expect(qrCard).toContainText('Dine-in');
        await expect(qrCard).toContainText(`1 × ${qr.flagged[0]}`);
        await expect(qrCard).toContainText('Diterima');

        const posCard = page.locator('article', { hasText: pos.invoice });
        await expect(posCard.getByRole('heading', { name: pos.invoice, exact: true })).toBeVisible();
        await expect(posCard).toContainText('Di tempat');
        await expect(posCard).toContainText(`1 × ${pos.flagged[0]}`);

        const numbers = await page.locator('article h2').allTextContents();
        expect(numbers.indexOf(qr.table_name)).toBeLessThan(numbers.indexOf(pos.invoice));
    });
});
