import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const byKey = (key) => roles.find((role) => role.key === key);

test.describe('57-sales-cost', () => {
    test.describe.configure({ timeout: 240_000 }); // three role sessions on a loaded runner
    test.use({ actionTimeout: 45_000 });

    // A recipe (18 g of a material averaging Rp 250 in the outlet) x 2 and a plain product (average Rp 4.000, buy
    // price Rp 5.000) x 1 are sold at the web till. HPP is frozen on the lines: Rp 4.500 x 2 + Rp 4.000.
    test('sale-cost: the sale takes the warehouse average, a later purchase price leaves it alone, a return reverses it', {
        tag: ['@REQ-002-AC-13', '@REQ-002-AC-14', '@REQ-002-AC-15'],
    }, async ({ asRole, arrange, uniq }) => {
        const material = arrange('purchasing.material', {
            title: uniq('Kopi'), purchased_by: 'outlet', recipe: 1, stock_outlet: 10000, avg_outlet: 250,
        });
        const plain = arrange('purchasing.plain-product', { title: uniq('Roti') });
        const customer = arrange('purchasing.till', {});

        // The cashier sells 2 portions of the recipe and 1 plain product to a customer, paying the exact amount.
        const till = await asRole(byKey('cashier'));
        await till.goto('/dashboard/transactions');
        const search = till.getByPlaceholder(/Cari produk/);
        await expect(search).toBeVisible({ timeout: 60_000 }); // the till is the heaviest page
        await search.fill(material.recipe_title);
        await till.getByRole('button', { name: new RegExp(material.recipe_title) }).click();
        await expect(till.getByText('1 item')).toBeVisible();
        await till.getByRole('button', { name: '+', exact: true }).click();
        await expect(till.getByText('2 item')).toBeVisible();
        await search.fill(plain.title);
        await till.getByRole('button', { name: new RegExp(plain.title) }).click();
        await expect(till.getByText('3 item')).toBeVisible();
        await till.getByRole('button', { name: 'Pilih pelanggan...' }).click();
        await till.getByText(customer.name).first().click();
        await till.getByRole('button', { name: 'Uang pas' }).click();
        await till.getByRole('button', { name: 'Selesaikan Transaksi' }).click();

        // The till opens the receipt, which carries the invoice number.
        await expect(till).toHaveURL(/transactions\/TRX-[A-Z0-9]+\/print/, { timeout: 60_000 });
        const invoice = new URL(till.url()).pathname.split('/').at(-2);
        expect(invoice).toMatch(/^TRX-/);
        await expect(till.getByText(invoice).first()).toBeVisible();

        // The owner (super-admin sees every cashier's sales) reads the profit report and handles the return.
        const owner = await asRole(byKey('super-admin'));
        const profit = async () => {
            await owner.goto(`/dashboard/reports/profits?invoice=${encodeURIComponent(invoice)}`);

            return owner.getByRole('row', { name: new RegExp(invoice) }).getByRole('cell').last();
        };

        // AC-13: revenue 50.000 - HPP (2 x 4.500 + 4.000) = Rp 37.000.
        await expect(await profit()).toHaveText(/Rp\s*37\.000/);

        // AC-14: 5 kg more arrive at Rp 400 per gram: (9.964 x 250 + 5.000 x 400) / 14.964 = Rp 300,12; the sale stays put.
        arrange('purchasing.po', { material_id: material.material_id, qty: 5, price: 400000, receive: 5 });
        await owner.goto(`/dashboard/raw-materials?search=${encodeURIComponent(material.title)}`);
        await expect(owner.getByRole('row', { name: new RegExp(material.title) })).toContainText(/14\.964\s*·\s*Rp\s*300,12/);
        await expect(await profit()).toHaveText(/Rp\s*37\.000/);

        // AC-15: returning 1 portion reverses its margin at the frozen cost: 20.000 - 4.500 = 15.500 (not at today's Rp 5.400).
        arrange('open-shift', { cashier: 'superadmin@e2e.test', cash: 0 });
        await owner.goto('/dashboard/transactions/history');
        const sale = owner.getByRole('row', { name: new RegExp(customer.name) });
        await sale.getByRole('link', { name: 'Retur' }).click();
        const line = owner.getByRole('row', { name: new RegExp(material.recipe_title) });
        await line.getByRole('spinbutton').fill('1');
        await line.getByPlaceholder('Alasan retur').fill('Salah pesan');
        await owner.getByRole('button', { name: 'Buat Draft' }).click();
        await owner.getByRole('button', { name: 'Selesaikan Retur' }).click();
        await expect(owner.getByText('Retur penjualan diselesaikan')).toBeVisible();

        await expect(await profit()).toHaveText(/Rp\s*21\.500/);
    });
});
