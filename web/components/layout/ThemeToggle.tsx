"use client";

import { useSyncExternalStore } from "react";

export type ThemePreference = "light" | "dark" | "system";
const STORAGE_KEY = "pricelens-theme";

/**
 * Runs inline in <head> before first paint so the correct theme is applied
 * without a flash. Kept as a string; must stay dependency-free.
 */
export const themeInitScript = `(function(){try{var k=${JSON.stringify(STORAGE_KEY)};var p=localStorage.getItem(k);var m=window.matchMedia('(prefers-color-scheme: dark)');var apply=function(){var pref=localStorage.getItem(k)||'system';var t=pref==='system'?(m.matches?'dark':'light'):pref;document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-theme-pref',pref);};apply();m.addEventListener('change',function(){if((localStorage.getItem(k)||'system')==='system')apply();});}catch(e){document.documentElement.setAttribute('data-theme',window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');}})();`;

function readPref(): ThemePreference {
  if (typeof document === "undefined") return "system";
  const v = document.documentElement.getAttribute("data-theme-pref");
  return v === "light" || v === "dark" ? v : "system";
}

const listeners = new Set<() => void>();
function subscribe(cb: () => void) {
  listeners.add(cb);
  return () => listeners.delete(cb);
}

function setPref(pref: ThemePreference) {
  try {
    if (pref === "system") localStorage.removeItem(STORAGE_KEY);
    else localStorage.setItem(STORAGE_KEY, pref);
  } catch {
    // storage unavailable: still apply for this page view
  }
  const dark = pref === "dark" || (pref === "system" && window.matchMedia("(prefers-color-scheme: dark)").matches);
  document.documentElement.setAttribute("data-theme", dark ? "dark" : "light");
  document.documentElement.setAttribute("data-theme-pref", pref);
  listeners.forEach((l) => l());
}

const ORDER: ThemePreference[] = ["system", "light", "dark"];
const LABEL: Record<ThemePreference, string> = { system: "System", light: "Light", dark: "Dark" };
const ICON: Record<ThemePreference, string> = { system: "◐", light: "☀", dark: "☾" };

export function ThemeToggle() {
  const pref = useSyncExternalStore(subscribe, readPref, () => "system" as ThemePreference);
  const next = ORDER[(ORDER.indexOf(pref) + 1) % ORDER.length];
  return (
    <button
      type="button"
      onClick={() => setPref(next)}
      className="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2 text-xs text-fg-muted hover:bg-surface-muted hover:text-fg"
      aria-label={`Theme: ${LABEL[pref]}. Switch to ${LABEL[next]}`}
      title={`Theme: ${LABEL[pref]}`}
    >
      <span aria-hidden="true">{ICON[pref]}</span>
      <span className="hidden sm:inline">{LABEL[pref]}</span>
    </button>
  );
}
