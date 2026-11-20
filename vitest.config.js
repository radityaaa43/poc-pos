import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));

// Node 25+ enables a global localStorage that warns when touched (i18next's detector does);
// turn it off in the workers so jsdom's localStorage is used. Older Nodes lack the flag.
const execArgv = process.allowedNodeEnvironmentFlags.has("--experimental-webstorage")
    ? ["--no-experimental-webstorage"]
    : [];

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: { "@": resolve(__dirname, "resources/js") },
    },
    test: {
        environment: "jsdom",
        setupFiles: ["resources/js/test/setup.js"],
        include: ["resources/js/**/*.test.{js,jsx}"],
        poolOptions: { forks: { execArgv } },
        coverage: {
            provider: "v8",
            reporter: ["text-summary", "html", "json-summary"],
            reportsDirectory: "coverage/frontend",
            include: ["resources/js/**/*.{js,jsx}"],
            exclude: ["resources/js/**/*.test.{js,jsx}", "resources/js/test/**"],
        },
    },
});
