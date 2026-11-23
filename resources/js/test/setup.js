import "@testing-library/jest-dom/vitest";
import "fake-indexeddb/auto";
import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

// Vitest globals are off, so RTL's auto-cleanup never registers itself.
afterEach(cleanup);

// Ziggy's route() comes from the Blade @routes directive, which doesn't exist in tests.
globalThis.route = (name) => `/${name}`;
