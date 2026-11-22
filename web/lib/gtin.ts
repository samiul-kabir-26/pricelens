/** Strip spaces and dashes users commonly paste into barcodes. */
export function normalizeGtin(input: string): string {
  return input.replace(/[\s-]/g, "");
}

/**
 * Validate a GTIN-8/12/13/14 (EAN/UPC) using the GS1 mod-10 check digit.
 * Mirrors the API's Form Request rule.
 */
export function isValidGtin(input: string): boolean {
  const gtin = normalizeGtin(input);
  if (!/^\d+$/.test(gtin) || ![8, 12, 13, 14].includes(gtin.length)) return false;

  const digits = gtin.split("").map(Number);
  const check = digits.pop()!;
  // Weights alternate 3,1,3,1... starting from the digit right before the check digit.
  let sum = 0;
  for (let i = digits.length - 1, w = 3; i >= 0; i--, w = w === 3 ? 1 : 3) {
    sum += digits[i] * w;
  }
  return (10 - (sum % 10)) % 10 === check;
}
