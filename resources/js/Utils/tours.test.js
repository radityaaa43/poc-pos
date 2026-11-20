import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import * as tours from "@/Utils/tours";

const tourMap = {
  dashboardTour: tours.dashboardTour,
  posTour: tours.posTour,
  productsTour: tours.productsTour,
  cashierShiftsTour: tours.cashierShiftsTour,
  reportsTour: tours.reportsTour,
};

describe.each(Object.entries(tourMap))("%s", (_name, tour) => {
  it("has at least one step", () => {
    expect(tour.steps.length).toBeGreaterThan(0);
  });

  it("has resolved, non-empty text and a data-tour selector on every step", () => {
    for (const step of tour.steps) {
      expect(step.element).toMatch(/^\[data-tour='[^']+'\]$/);
      for (const field of ["title", "description"]) {
        const text = step.popover[field];
        expect(text, `${step.element} ${field}`).toBeTruthy();
        expect(text.startsWith("tour."), `${step.element} ${field}: ${text}`).toBe(false);
      }
    }
  });
});

const jsxFiles = (dir) =>
  readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) return jsxFiles(path);
    return path.endsWith(".jsx") && !path.endsWith(".test.jsx") ? [path] : [];
  });

describe("tour selectors", () => {
  it("each data-tour target exists in some page or component", () => {
    const source = readFileSync("resources/js/Utils/tours.js", "utf8");
    const targets = [...source.matchAll(/data-tour='([^']+)'/g)].map((m) => m[1]);
    const jsx = jsxFiles("resources/js").map((f) => readFileSync(f, "utf8")).join("\n");

    expect(targets.length).toBeGreaterThan(0);
    const missing = targets.filter((t) => !jsx.includes(`data-tour="${t}"`));
    expect(missing).toEqual([]);
  });
});
