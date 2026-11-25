import { inflateRawSync } from 'node:zlib';
import { expect } from '@playwright/test';

// "Rp 62.000" as the UI prints it (id-ID currency, a no-break space after Rp).
export const rp = (amount) => new RegExp(`Rp\\s${String(amount).replace(/\B(?=(\d{3})+(?!\d))/g, '\\.')}(?!\\d)`);

// A summary card on the report pages: matches its title, value and description in one go.
export const card = (page, title) => page.locator('[data-tour="reports-summary"] > div').filter({ hasText: title });

export async function expectCard(page, title, value, description) {
    const text = [title, value, description].filter(Boolean).map((part) => (part instanceof RegExp ? part.source : part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
    // innerText: the card puts title, value and description in separate blocks, which textContent glues together.
    await expect(card(page, title)).toContainText(new RegExp(text.join('\\s*')), { useInnerText: true });
}

// Inertia reloads the page props after a filter request; the filter panel re-renders (and a
// headless-ui listbox opened meanwhile is detached), so wait for the XHR and for the network to go quiet.
const settled = async (page, action) => {
    const reload = page.waitForResponse((response) => response.request().method() === 'GET' && response.url().includes('/reports/'));
    await action();
    await reload;
    await page.waitForLoadState('networkidle');
};

// Clears every filter with the X button of the open panel (opens the panel first when it is closed).
export async function resetFilters(page) {
    if (!(await page.getByRole('button', { name: 'Terapkan' }).isVisible())) {
        await page.getByRole('button', { name: 'Filter', exact: true }).click();
    }
    await settled(page, () => page.locator('button:has(svg.tabler-icon-x)').click());
}

// Opens the filter panel, sets the given fields (Sales, Profit, Insights share the same form) and applies it.
export async function applyFilters(page, { start, end, invoice, option } = {}) {
    // The panel closes after Terapkan but stays open after the reset button.
    if (!(await page.getByRole('button', { name: 'Terapkan' }).isVisible())) {
        await page.getByRole('button', { name: 'Filter', exact: true }).click();
    }
    if (start !== undefined) await page.locator('input[type=date]').first().fill(start);
    if (end !== undefined) await page.locator('input[type=date]').nth(1).fill(end);
    if (invoice !== undefined) await page.getByPlaceholder('TRX-...').fill(invoice);
    // option: { placeholder: 'Semua kasir', choose: 'E2E Cashier' } for the headless-ui selects.
    if (option) {
        await page.getByRole('button', { name: option.placeholder }).click();
        const choice = page.getByRole('option', { name: option.choose });
        await expect(choice).toBeVisible();
        await choice.click();
    }
    await settled(page, () => page.getByRole('button', { name: 'Terapkan' }).click());
}

// The sale rows of the Sales and Profit tables (the Profit page repeats them as hidden mobile cards).
export const saleRow = (page, invoice) => page.getByRole('row').filter({ hasText: invoice });

export const switchOutlet = (page, label) => page.getByTitle('Pilih outlet').selectOption({ label });

// Reads an .xlsx buffer into rows of cell text. Enough for a one-sheet export: the zip entries are
// located through the central directory and inflated with zlib, so no extra dependency is needed.
export function readXlsx(buffer) {
    const entries = {};
    const end = buffer.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
    let offset = buffer.readUInt32LE(end + 16);
    for (let i = 0, count = buffer.readUInt16LE(end + 10); i < count; i++) {
        const method = buffer.readUInt16LE(offset + 10);
        const size = buffer.readUInt32LE(offset + 20);
        const nameLength = buffer.readUInt16LE(offset + 28);
        const extraLength = buffer.readUInt16LE(offset + 30);
        const commentLength = buffer.readUInt16LE(offset + 32);
        const local = buffer.readUInt32LE(offset + 42);
        const name = buffer.toString('utf8', offset + 46, offset + 46 + nameLength);
        const start = local + 30 + buffer.readUInt16LE(local + 26) + buffer.readUInt16LE(local + 28);
        const data = buffer.subarray(start, start + size);
        entries[name] = method === 8 ? inflateRawSync(data).toString('utf8') : data.toString('utf8');
        offset += 46 + nameLength + extraLength + commentLength;
    }

    const unescape = (text) => text.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'").replace(/&amp;/g, '&');
    const shared = [...(entries['xl/sharedStrings.xml'] ?? '').matchAll(/<si>(.*?)<\/si>/gs)]
        .map(([, si]) => unescape([...si.matchAll(/<t[^>]*>(.*?)<\/t>/gs)].map(([, t]) => t).join('')));

    return [...entries['xl/worksheets/sheet1.xml'].matchAll(/<row[^>]*>(.*?)<\/row>/gs)].map(([, row]) => Array.from(
        // Laravel Excel leaves zero amounts out (no <c> at all), so cells are placed by their column letter.
        [...row.matchAll(/<c([^>]*?)(?:\/>|>(.*?)<\/c>)/gs)].reduce((cells, [, attrs, inner = '']) => {
            const column = [...attrs.match(/r="([A-Z]+)\d+"/)[1]].reduce((n, letter) => n * 26 + letter.charCodeAt(0) - 64, 0) - 1;
            const value = inner.match(/<v>(.*?)<\/v>/s)?.[1] ?? inner.match(/<t[^>]*>(.*?)<\/t>/s)?.[1] ?? '';
            cells[column] = /t="s"/.test(attrs) ? shared[Number(value)] : unescape(value);

            return cells;
        }, []), (cell) => cell ?? ''));
}

// The body of a Table.Card on the Insights page: the card's title and its table are sibling blocks.
export const tableUnder = (page, title) => page.getByText(title, { exact: true }).locator('xpath=../following-sibling::div[1]');

// Fetches a document link with the page's own session and checks it is a PDF served under `filename`.
export async function expectPdf(page, href, filename) {
    // The first render of a PDF (fonts, barcode) is slow, especially on a busy machine.
    const response = await page.request.get(href, { timeout: 60_000 });
    expect(response.status(), href).toBe(200);
    expect(response.headers()['content-type']).toContain('application/pdf');
    expect(response.headers()['content-disposition']).toContain(`filename=${filename}`);
    expect((await response.body()).subarray(0, 5).toString()).toBe('%PDF-');

    return response;
}
