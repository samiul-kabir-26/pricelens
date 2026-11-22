"use client";

import { useId } from "react";
import type { SearchFormValues } from "@/lib/schemas";
import { CONDITIONS } from "@/lib/schemas";
import { Checkbox, TextField } from "@/components/ui/Input";
import { SelectField } from "@/components/ui/Select";
import { humanize } from "@/lib/format";

type OptionsValue = SearchFormValues["options"];

const COUNTRIES = ["US", "GB", "CA", "AU", "DE", "FR", "IT", "ES", "NL", "SE", "PL", "JP", "IN", "BD", "SG", "AE"];
const LANGUAGES = ["en", "de", "fr", "es", "it", "nl", "sv", "pl", "ja", "bn", "ar"];
const CURRENCIES = ["USD", "EUR", "GBP", "CAD", "AUD", "SEK", "PLN", "JPY", "INR", "BDT", "SGD", "AED"];

const regionNames = typeof Intl !== "undefined" && "DisplayNames" in Intl ? new Intl.DisplayNames(["en"], { type: "region" }) : null;
const languageNames = typeof Intl !== "undefined" && "DisplayNames" in Intl ? new Intl.DisplayNames(["en"], { type: "language" }) : null;

const withCurrent = (list: string[], current: string) => (list.includes(current) ? list : [current, ...list]);

interface OptionsPanelProps {
  value: OptionsValue;
  onChange: (value: OptionsValue) => void;
  errors: Record<string, string>;
}

export function OptionsPanel({ value, onChange, errors }: OptionsPanelProps) {
  const id = useId();
  const set = <K extends keyof OptionsValue>(key: K, v: OptionsValue[K]) => onChange({ ...value, [key]: v });
  const confidencePct = Math.round(value.min_confidence * 100);

  return (
    <fieldset className="space-y-4">
      <legend className="text-sm font-semibold text-fg">Options</legend>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <SelectField
          label="Country"
          value={value.country}
          onChange={(e) => set("country", e.target.value)}
          error={errors["options.country"]}
          options={withCurrent(COUNTRIES, value.country).map((c) => ({ value: c, label: `${regionNames?.of(c) ?? c} (${c})` }))}
        />
        <SelectField
          label="Language"
          value={value.language}
          onChange={(e) => set("language", e.target.value)}
          error={errors["options.language"]}
          options={withCurrent(LANGUAGES, value.language).map((l) => ({ value: l, label: `${languageNames?.of(l) ?? l} (${l})` }))}
        />
        <SelectField
          label="Target currency"
          value={value.target_currency}
          onChange={(e) => set("target_currency", e.target.value)}
          error={errors["options.target_currency"]}
          options={withCurrent(CURRENCIES, value.target_currency).map((c) => ({ value: c, label: c }))}
        />
        <SelectField
          label="Condition"
          value={value.condition}
          onChange={(e) => set("condition", e.target.value as OptionsValue["condition"])}
          error={errors["options.condition"]}
          options={CONDITIONS.map((c) => ({ value: c, label: humanize(c) }))}
        />
        <TextField
          label="Max results"
          type="number"
          inputMode="numeric"
          min={1}
          max={100}
          value={Number.isNaN(value.max_results) ? "" : value.max_results}
          onChange={(e) => set("max_results", e.target.value === "" ? Number.NaN : Number(e.target.value))}
          error={errors["options.max_results"]}
        />
        <div className="space-y-1">
          <label htmlFor={`${id}-conf`} className="block text-sm font-medium text-fg">
            Minimum confidence
          </label>
          <div className="flex items-center gap-3">
            <input
              id={`${id}-conf`}
              type="range"
              min={0}
              max={1}
              step={0.05}
              value={value.min_confidence}
              onChange={(e) => set("min_confidence", Number(e.target.value))}
              aria-valuetext={`${confidencePct}%`}
              className="w-full accent-[var(--accent)]"
            />
            <output htmlFor={`${id}-conf`} className="w-12 text-right text-sm tabular-nums text-fg">
              {confidencePct}%
            </output>
          </div>
          {errors["options.min_confidence"] && (
            <p role="alert" className="text-xs text-danger">
              {errors["options.min_confidence"]}
            </p>
          )}
        </div>
      </div>
      <div className="grid gap-2 sm:grid-cols-2">
        <Checkbox
          checked={value.include_possible}
          onChange={(e) => set("include_possible", e.target.checked)}
          label="Include possible matches"
          description="Also return lower-confidence offers"
        />
        <Checkbox
          checked={value.fresh}
          onChange={(e) => set("fresh", e.target.checked)}
          label="Bypass cache"
          description="Fetch every page fresh instead of using cached results"
        />
      </div>
    </fieldset>
  );
}
