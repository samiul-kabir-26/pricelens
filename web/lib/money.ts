import type { Money } from "./types";

const formatterCache = new Map<string, Intl.NumberFormat>();

function getFormatter(currency: string, locale?: string): Intl.NumberFormat | null {
  const key = `${locale ?? ""}|${currency}`;
  const cached = formatterCache.get(key);
  if (cached) return cached;
  try {
    const fmt = new Intl.NumberFormat(locale, {
      style: "currency",
      currency: currency.toUpperCase(),
      currencyDisplay: "narrowSymbol",
    });
    formatterCache.set(key, fmt);
    return fmt;
  } catch {
    return null;
  }
}

/** Parse a decimal amount string to a number; returns null for blanks or garbage. */
export function parseAmount(amount: string | number | null | undefined): number | null {
  if (amount === null || amount === undefined) return null;
  if (typeof amount === "number") return Number.isFinite(amount) ? amount : null;
  const trimmed = amount.trim();
  if (trimmed === "" || !/^-?\d+(\.\d+)?$/.test(trimmed)) return null;
  const n = Number(trimmed);
  return Number.isFinite(n) ? n : null;
}

/**
 * Format an amount + currency with Intl.NumberFormat.
 * Falls back to "<amount> <CUR>" for unknown currency codes.
 */
export function formatAmount(
  amount: string | number | null | undefined,
  currency: string | null | undefined,
  locale?: string,
): string {
  const n = parseAmount(amount);
  if (n === null) return "—";
  if (!currency) return n.toFixed(2);
  const fmt = getFormatter(currency, locale);
  if (!fmt) return `${n.toFixed(2)} ${currency.toUpperCase()}`;
  return fmt.format(n);
}

/** Format a Money value; null/undefined renders as an em dash. */
export function formatMoney(money: Money | null | undefined, locale?: string): string {
  if (!money) return "—";
  return formatAmount(money.amount, money.currency, locale);
}

/** Numeric value of a Money (or null). */
export function moneyValue(money: Money | null | undefined): number | null {
  return money ? parseAmount(money.amount) : null;
}

/** Comparator for sorting; nulls always sort last regardless of direction. */
export function compareMoney(
  a: Money | null | undefined,
  b: Money | null | undefined,
  direction: "asc" | "desc" = "asc",
): number {
  const av = moneyValue(a);
  const bv = moneyValue(b);
  if (av === null && bv === null) return 0;
  if (av === null) return 1;
  if (bv === null) return -1;
  return direction === "asc" ? av - bv : bv - av;
}

/** True when list price is meaningfully higher than price (i.e. show strikethrough). */
export function isDiscounted(price: Money | null, listPrice: Money | null): boolean {
  const p = moneyValue(price);
  const l = moneyValue(listPrice);
  return p !== null && l !== null && l > p && price?.currency === listPrice?.currency;
}

/** Percentage saved vs list price, rounded; null when not discounted. */
export function discountPercent(price: Money | null, listPrice: Money | null): number | null {
  if (!isDiscounted(price, listPrice)) return null;
  const p = moneyValue(price)!;
  const l = moneyValue(listPrice)!;
  return Math.round(((l - p) / l) * 100);
}

/** Normalise user input like "1,299.5" to a 2dp decimal string, or null when invalid. */
export function toAmountString(input: string): string | null {
  const cleaned = input.replace(/[\s,]/g, "");
  if (!/^\d+(\.\d{1,4})?$/.test(cleaned)) return null;
  return Number(cleaned).toFixed(2);
}
