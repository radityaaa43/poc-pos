import { test, expect } from '../../support/fixtures.js';
import { addMenuWithOptions } from '../../support/dine-modifiers.js';

// REQ-008 AC-16 / BR-3: an option switched off after the guest opened the page refuses the order, naming the menu
// and the option, and the guest keeps the rest of the cart.
test.describe('49-dine-modifiers', () => {
    test.describe.configure({ timeout: 240_000 });

    test('an option switched off after the page opened refuses the order, names it, and keeps the cart', { tag: ['@REQ-008-AC-16'] }, async ({ page, arrange, uniq }) => {
        const title = uniq('Teh');
        const { product_id } = arrange('modifier-menu', { title });
        const { token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });

        const cart = await addMenuWithOptions(page, { token, title, option: 'Large', note: 'panas' });
        // The owner switches Large off at this outlet while the guest still has the menu open.
        arrange('modifier-change', { product: product_id, option: 'Large' });
        await cart.getByRole('button', { name: 'Pesan', exact: true }).click();

        await expect(page.getByText(`Opsi Large untuk ${title} tidak tersedia lagi.`)).toBeVisible();
        // The other choices stay on the page so the guest can change the option.
        await expect(page.getByRole('button', { name: /1 item/ })).toBeVisible();
    });
});
