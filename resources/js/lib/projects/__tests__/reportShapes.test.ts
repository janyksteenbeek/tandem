import { describe, expect, it } from "vitest";
import {
    categoryBreakdown,
    formatHours,
    padWeeks,
    presetForDays,
    quarterDays,
    throughputPerWeek,
    yearWeek,
} from "@/lib/projects/reportShapes";

describe("reportShapes", () => {
    it("counts the days since the quarter started with a floor of seven", () => {
        expect(quarterDays(new Date("2026-11-07T10:00:00Z"))).toBe(37);
        expect(quarterDays(new Date("2026-10-02T10:00:00Z"))).toBe(7);
    });

    it("maps a day count back to its preset", () => {
        const today = new Date("2026-11-07T10:00:00Z");

        expect(presetForDays(28, today)).toBe("4w");
        expect(presetForDays(37, today)).toBe("quarter");
        expect(presetForDays(30, today)).toBeNull();
    });

    it("averages completed issues per week", () => {
        expect(throughputPerWeek([])).toBe(0);
        expect(
            throughputPerWeek([
                { week: "2026-10-26", created: 4, completed: 2 },
                { week: "2026-11-02", created: 1, completed: 5 },
            ]),
        ).toBe(3.5);
    });

    it("builds ordered breakdown segments that skip empty categories", () => {
        const segments = categoryBreakdown({
            backlog: 2,
            unstarted: 0,
            started: 1,
            completed: 5,
            canceled: 0,
        });

        expect(segments.map((s) => s.category)).toEqual([
            "backlog",
            "started",
            "completed",
        ]);
        expect(segments[2].share).toBeCloseTo(0.625);
        expect(
            categoryBreakdown({
                backlog: 0,
                unstarted: 0,
                started: 0,
                completed: 0,
                canceled: 0,
            }),
        ).toEqual([]);
    });

    it("formats hours as hours, days or weeks", () => {
        expect(formatHours(null)).toBeNull();
        expect(formatHours(5.4)).toBe("5h");
        expect(formatHours(36)).toBe("1.5d");
        expect(formatHours(24 * 21)).toBe("3w");
    });

    it("matches MySQL YEARWEEK mode 3 across the year boundary", () => {
        expect(yearWeek(new Date("2026-01-01T00:00:00Z"))).toBe("202601");
        expect(yearWeek(new Date("2027-01-01T00:00:00Z"))).toBe("202653");
        expect(yearWeek(new Date("2026-11-07T00:00:00Z"))).toBe("202645");
    });

    it("pads sparse weekly rows to a fixed window ending this week", () => {
        const weeks = padWeeks(
            [{ week: "202645", completed: 3 }],
            3,
            new Date("2026-11-07T00:00:00Z"),
        );

        expect(weeks.map((w) => w.week)).toEqual([
            "202643",
            "202644",
            "202645",
        ]);
        expect(weeks.map((w) => w.completed)).toEqual([0, 0, 3]);
    });
});
