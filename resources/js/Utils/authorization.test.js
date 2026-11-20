import { describe, it, expect } from "vitest";
import {
    resolveAuthorizationState,
    can,
    canAny,
    canAll,
    isSuperAdmin,
} from "./authorization";

describe("resolveAuthorizationState", () => {
    it("defaults to no permissions and not super when auth is missing", () => {
        expect(resolveAuthorizationState(undefined)).toEqual({
            permissions: {},
            super: false,
        });
    });
});

describe("can", () => {
    it("grants a permission that is true", () => {
        expect(can("x", { permissions: { x: true } })).toBe(true);
    });

    it("denies a permission that is false", () => {
        expect(can("x", { permissions: { x: false } })).toBe(false);
    });

    it("denies a permission that is missing", () => {
        expect(can("x", { permissions: {} })).toBe(false);
    });

    it("grants everything to a super admin", () => {
        expect(can("anything", { super: true })).toBe(true);
    });

    it("does not treat a string 'true' as super admin", () => {
        expect(can("anything", { super: "true" })).toBe(false);
    });
});

describe("canAny", () => {
    it("returns false for an empty list", () => {
        expect(canAny([], { permissions: { a: true } })).toBe(false);
    });

    it("returns false when given a string instead of a list", () => {
        expect(canAny("x", { permissions: { x: true } })).toBe(false);
    });

    it("returns true when at least one permission is granted", () => {
        expect(canAny(["a", "b"], { permissions: { b: true } })).toBe(true);
    });
});

describe("canAll", () => {
    it("returns false when one permission is missing", () => {
        expect(canAll(["a", "b"], { permissions: { a: true } })).toBe(false);
    });

    it("returns true when every permission is granted", () => {
        expect(
            canAll(["a", "b"], { permissions: { a: true, b: true } }),
        ).toBe(true);
    });

    it("returns false for an empty list even for a super admin", () => {
        expect(canAll([], { super: true })).toBe(false);
    });
});

describe("isSuperAdmin", () => {
    it("returns true for a super admin", () => {
        expect(isSuperAdmin({ super: true })).toBe(true);
    });
});
