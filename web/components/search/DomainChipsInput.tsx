"use client";

import { useId, useState, type ClipboardEvent, type KeyboardEvent } from "react";
import { isHostname, normalizeDomain } from "@/lib/schemas";

interface DomainChipsInputProps {
  value: string[];
  onChange: (domains: string[]) => void;
  label?: string;
  error?: string;
  max?: number;
}

/** Free-text hostnames as removable chips. Enter, comma, space or paste adds. */
export function DomainChipsInput({ value, onChange, label = "Extra domains", error, max = 50 }: DomainChipsInputProps) {
  const id = useId();
  const [draft, setDraft] = useState("");
  const [localError, setLocalError] = useState<string | null>(null);

  const add = (raw: string[]) => {
    const next = [...value];
    const invalid: string[] = [];
    for (const r of raw) {
      const d = normalizeDomain(r);
      if (!d) continue;
      if (!isHostname(d)) {
        invalid.push(r.trim());
        continue;
      }
      if (!next.includes(d) && next.length < max) next.push(d);
    }
    if (next.length !== value.length) onChange(next);
    setLocalError(invalid.length ? `Not a valid hostname: ${invalid.join(", ")}` : null);
    return invalid.length === 0;
  };

  const commitDraft = () => {
    if (draft.trim() === "") return;
    if (add([draft])) setDraft("");
  };

  const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter" || e.key === "," || e.key === " ") {
      e.preventDefault();
      commitDraft();
    } else if (e.key === "Backspace" && draft === "" && value.length > 0) {
      onChange(value.slice(0, -1));
    }
  };

  const onPaste = (e: ClipboardEvent<HTMLInputElement>) => {
    const text = e.clipboardData.getData("text");
    if (/[\s,]/.test(text)) {
      e.preventDefault();
      add(text.split(/[\s,]+/));
    }
  };

  const shownError = localError ?? error;

  return (
    <div className="space-y-1">
      <label htmlFor={id} className="block text-sm font-medium text-fg">
        {label}
      </label>
      <div className="flex flex-wrap items-center gap-1.5 rounded-md border border-border bg-surface px-2 py-1.5 focus-within:border-accent focus-within:ring-2 focus-within:ring-accent/30">
        <ul className="contents" aria-label="Selected domains">
          {value.map((d) => (
            <li key={d} className="inline-flex items-center gap-1 rounded bg-surface-muted px-2 py-0.5 text-xs text-fg">
              {d}
              <button
                type="button"
                onClick={() => onChange(value.filter((x) => x !== d))}
                className="text-fg-muted hover:text-fg"
                aria-label={`Remove ${d}`}
              >
                ✕
              </button>
            </li>
          ))}
        </ul>
        <input
          id={id}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={onKeyDown}
          onPaste={onPaste}
          onBlur={commitDraft}
          placeholder={value.length ? "" : "shop.example.com"}
          aria-describedby={`${id}-hint${shownError ? ` ${id}-err` : ""}`}
          aria-invalid={shownError ? true : undefined}
          className="min-w-[10rem] flex-1 bg-transparent py-0.5 text-sm text-fg placeholder:text-fg-muted/70 focus:outline-none"
        />
      </div>
      <p id={`${id}-hint`} className="text-xs text-fg-muted">
        Press Enter or comma to add. Backspace removes the last one.
      </p>
      {shownError && (
        <p id={`${id}-err`} role="alert" className="text-xs text-danger">
          {shownError}
        </p>
      )}
    </div>
  );
}
