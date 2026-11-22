import { describe, expect, it } from "vitest";
import { isValidGtin, normalizeGtin } from "@/lib/gtin";

describe("isValidGtin", () => {
  it.each([
    ["0027242923782", "GTIN-13 / EAN"],
    ["4006381333931", "EAN-13"],
    ["036000291452", "UPC-A (GTIN-12)"],
    ["96385074", "GTIN-8"],
    ["10012345678902", "GTIN-14"],
    ["0027-2429 23782", "with separators"],
  ])("accepts %s (%s)", (gtin) => {
    expect(isValidGtin(gtin)).toBe(true);
  });

  it.each([
    ["0027242923781", "wrong check digit"],
    ["036000291453", "wrong UPC check digit"],
    ["12345", "bad length"],
    ["00272429237820", "14 digits wrong check"],
    ["ABCDEFGHIJKLM", "non-numeric"],
    ["", "empty"],
  ])("rejects %s (%s)", (gtin) => {
    expect(isValidGtin(gtin)).toBe(false);
  });

  it("normalizes separators", () => {
    expect(normalizeGtin(" 0027-242 923782 ")).toBe("0027242923782");
  });
});
