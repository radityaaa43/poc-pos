import "@testing-library/jest-dom/vitest";
import "fake-indexeddb/auto";

// Ziggy's route() comes from the Blade @routes directive, which doesn't exist in tests.
globalThis.route = (name) => `/${name}`;
