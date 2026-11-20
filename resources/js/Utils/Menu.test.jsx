import { readFileSync } from "node:fs";
import { join } from "node:path";
import { renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { usePage } from "@inertiajs/react";
import Menu from "./Menu";

vi.mock("@inertiajs/react", () => ({ usePage: vi.fn() }));
vi.mock("react-i18next", () => ({ useTranslation: () => ({ t: (k) => k }) }));

function renderMenu({ url = "/dashboard", auth }) {
    usePage.mockReturnValue({ url, props: { auth } });
    return renderHook(() => Menu()).result.current;
}

// Every item: top-level details plus subdetails of parents such as "users".
function allItems(sections) {
    return sections.flatMap((s) =>
        s.details.flatMap((d) => [d, ...(d.subdetails ?? [])]),
    );
}

const leaves = (sections) => allItems(sections).filter((i) => i.href);

describe("sidebar Menu", () => {
    beforeEach(() => vi.clearAllMocks());

    it("grants every item to a super admin", () => {
        const items = allItems(renderMenu({ auth: { super: true } }));

        expect(items.length).toBeGreaterThan(0);
        expect(items.every((i) => i.permissions === true)).toBe(true);
    });

    it("grants only the item matching the user's permission", () => {
        const granted = allItems(
            renderMenu({
                auth: { super: false, permissions: { "categories-access": true } },
            }),
        ).filter((i) => i.permissions === true);

        expect(granted).toHaveLength(1);
        expect(granted[0].href).toBe("/categories.index");
    });

    it("marks only the item for the current url as active", () => {
        const items = leaves(
            renderMenu({ url: "/dashboard/categories", auth: { super: true } }),
        );
        const byHref = (h) => items.find((i) => i.href === h);

        expect(byHref("/categories.index").active).toBe(true);
        expect(byHref("/dashboard").active).toBe(false);
    });

    it("has 46 items with unique hrefs", () => {
        const hrefs = leaves(renderMenu({ auth: { super: true } })).map(
            (i) => i.href,
        );

        expect(hrefs).toHaveLength(46);
        expect(new Set(hrefs).size).toBe(46);
    });

    it("every menu permission exists in PermissionSeeder", () => {
        const read = (p) => readFileSync(join(process.cwd(), p), "utf8");
        const menu = read("resources/js/Utils/Menu.jsx");
        const seeder = read("database/seeders/PermissionSeeder.php");

        const used = new Set();
        for (const m of menu.matchAll(/hasAnyPermission\(\[([^\]]*)\]\)/g)) {
            for (const s of m[1].matchAll(/["']([^"']+)["']/g)) used.add(s[1]);
        }
        const seeded = new Set(
            [...seeder.matchAll(/['"]([a-z][a-z0-9-]*)['"]/g)].map((m) => m[1]),
        );

        expect(used.size).toBeGreaterThan(0);
        expect([...used].filter((p) => !seeded.has(p))).toEqual([]);
    });
});
