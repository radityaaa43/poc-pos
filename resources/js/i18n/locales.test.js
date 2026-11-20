import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const load = (name) => JSON.parse(readFileSync(resolve(process.cwd(), `resources/js/i18n/locales/${name}.json`), "utf8"));

const flatten = (obj, prefix = "") =>
    Object.entries(obj).flatMap(([key, value]) =>
        value !== null && typeof value === "object"
            ? flatten(value, `${prefix}${key}.`)
            : [[`${prefix}${key}`, value]],
    );

// Known mismatch, tracked in #12. The fix PR for #12 empties this list.
const KNOWN_GAPS = ["sidebar.items.outlets", "sidebar.menu.outlets"]; // #12

const id = Object.fromEntries(flatten(load("id")));
const en = Object.fromEntries(flatten(load("en")));

describe("locale files", () => {
    it("have the same keys, except the known gaps", () => {
        const idKeys = Object.keys(id);
        const enKeys = Object.keys(en);
        const diff = [
            ...idKeys.filter((k) => !(k in en)),
            ...enKeys.filter((k) => !(k in id)),
        ].sort();

        expect(diff).toEqual([...KNOWN_GAPS].sort());
    });

    it.each([
        ["id", id],
        ["en", en],
    ])("%s has no empty values", (_name, dict) => {
        const empty = Object.entries(dict)
            .filter(([, v]) => v === "")
            .map(([k]) => k);

        expect(empty).toEqual([]);
    });
});
