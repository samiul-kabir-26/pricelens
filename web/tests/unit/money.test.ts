import { describe, expect, it } from "vitest";
import { compareMoney, discountPercent, formatAmount, formatMoney, isDiscounted, parseAmount, toAmountString } from "@/lib/money";

describe("money formatting", () => {
  it("formats string amounts with Intl currency style", () => {
    expect(formatMoney({ amount: "329.99", currency: "USD" }, "en-US")).toBe("$329.99");
    expect(formatMoney({ amount: "1299.5", currency: "USD" }, "en-US")).toBe("$1,299.50");
    expect(formatMoney({ amount: "0.00", currency: "USD" }, "en-US")).toBe("$0.00");
  });

  it("respects currency minor units and locale", () => {
    expect(formatMoney({ amount: "42000", currency: "JPY" }, "en-US")).toBe("¥42,000");
    expect(formatMoney({ amount: "329.99", currency: "EUR" }, "de-DE")).toMatch(/^329,99\s€$/);
  });

  it("renders an em dash for null or unparseable values", () => {
    expect(formatMoney(null)).toBe("—");
    expect(formatMoney(undefined)).toBe("—");
    expect(formatMoney({ amount: "abc", currency: "USD" })).toBe("—");
  });

  it("falls back for unknown currency codes", () => {
    expect(formatAmount("10", "NOTACURRENCY", "en-US")).toBe("10.00 NOTACURRENCY");
  });

  it("parses decimal strings strictly", () => {
    expect(parseAmount("329.99")).toBe(329.99);
    expect(parseAmount("-1.5")).toBe(-1.5);
    expect(parseAmount("1,299.00")).toBeNull();
    expect(parseAmount("")).toBeNull();
    expect(parseAmount(null)).toBeNull();
  });

  it("compares money with nulls last in both directions", () => {
    const a = { amount: "10.00", currency: "USD" };
    const b = { amount: "20.00", currency: "USD" };
    expect([b, null, a].sort((x, y) => compareMoney(x, y, "asc"))).toEqual([a, b, null]);
    expect([a, null, b].sort((x, y) => compareMoney(x, y, "desc"))).toEqual([b, a, null]);
  });

  it("detects discounts against list price", () => {
    const price = { amount: "329.99", currency: "USD" };
    const list = { amount: "399.99", currency: "USD" };
    expect(isDiscounted(price, list)).toBe(true);
    expect(discountPercent(price, list)).toBe(18);
    expect(isDiscounted(price, { amount: "399.99", currency: "EUR" })).toBe(false);
    expect(isDiscounted(price, null)).toBe(false);
  });

  it("normalizes user-entered amounts", () => {
    expect(toAmountString("1,299.5")).toBe("1299.50");
    expect(toAmountString("abc")).toBeNull();
  });
});
