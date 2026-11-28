import { expect, test } from "../../support/fixtures.js";
import { signIn } from "../../support/platform.js";

// REQ-003 AC-13 (#564) BR-1, BR-2: the monthly force-close count per outlet, with a link to the
// over-hold shift, in Indonesian and English.
test.describe("88-force-close-summary", () => {
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test(
        "a manager reads the month's closed, force-closed and over-hold counts and opens the held shift",
        { tag: ["@REQ-003-AC-13"] },
        async ({ page, arrange, uniq }) => {
            const { month, outlet_id: outletId, held_id: heldId } = arrange("shiftclose.summary-shifts", {
                marker: uniq("summary"),
            });
            await signIn(page, "manager@e2e.test");
            await page.goto(`/dashboard/cashier-shifts?summary_outlet_id=${outletId}&summary_month=${month}`);

            const card = page.getByRole("region", { name: "Ringkasan tutup paksa" });
            await expect(card.getByText("Shift ditutup", { exact: true }).locator("xpath=following-sibling::dd")).toHaveText("3");
            await expect(card.getByText("Ditutup paksa", { exact: true }).locator("xpath=following-sibling::dd")).toHaveText("2");
            await expect(card.getByText("Ditutup paksa saat tertahan", { exact: true }).locator("xpath=following-sibling::dd")).toHaveText("1");
            await expect(card.getByText("E2E Cashier")).toBeVisible();
            await expect(card.getByText("Tamu sudah pulang")).toBeVisible();

            await card.getByRole("link", { name: `Lihat shift #${heldId}` }).click();
            await page.waitForURL(`**/dashboard/cashier-shifts/${heldId}`);
            await expect(page.getByText("Force Closed", { exact: true })).toBeVisible();

            await page.evaluate(() => localStorage.setItem("i18nextLng", "en"));
            await page.goto(`/dashboard/cashier-shifts?summary_outlet_id=${outletId}&summary_month=${month}`);
            const english = page.getByRole("region", { name: "Force-close summary" });
            await expect(english.getByText("Shifts closed", { exact: true }).locator("xpath=following-sibling::dd")).toHaveText("3");
            await expect(english.getByText("Force-closed", { exact: true }).locator("xpath=following-sibling::dd")).toHaveText("2");
            await expect(english.getByText("Force-closed over a hold", { exact: true }).locator("xpath=following-sibling::dd")).toHaveText("1");
            await expect(english.getByRole("link", { name: `View shift #${heldId}` })).toBeVisible();
        }
    );
});
