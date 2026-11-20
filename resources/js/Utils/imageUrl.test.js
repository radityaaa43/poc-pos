import { describe, it, expect } from "vitest";
import { getImageUrl, getProductImageUrl } from "./imageUrl";

const PLACEHOLDER = "/images/product-placeholder.svg";

describe("getImageUrl", () => {
    it("returns null for empty values", () => {
        expect(getImageUrl(null)).toBeNull();
        expect(getImageUrl("")).toBeNull();
    });

    it("maps default.jpg to the placeholder", () => {
        expect(getImageUrl("default.jpg")).toBe(PLACEHOLDER);
        expect(getImageUrl("products/default.jpg")).toBe(PLACEHOLDER);
    });

    it("leaves full URLs and /storage paths unchanged", () => {
        expect(getImageUrl("https://cdn.x/a.png")).toBe("https://cdn.x/a.png");
        expect(getImageUrl("http://x/a.png")).toBe("http://x/a.png");
        expect(getImageUrl("/storage/products/a.png")).toBe(
            "/storage/products/a.png",
        );
    });

    it("prefixes filenames with the storage folder", () => {
        expect(getImageUrl("a.png")).toBe("/storage/products/a.png");
        expect(getImageUrl("a.png", "categories")).toBe(
            "/storage/categories/a.png",
        );
    });
});

describe("getProductImageUrl", () => {
    it("falls back to the placeholder when there is no image", () => {
        expect(getProductImageUrl(null)).toBe(PLACEHOLDER);
    });
});
