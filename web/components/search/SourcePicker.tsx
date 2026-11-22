"use client";

import { useQuery } from "@tanstack/react-query";
import { useId, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { qk } from "@/lib/query-keys";
import type { SearchFormValues } from "@/lib/schemas";
import type { ListResponse, Source } from "@/lib/types";
import { Checkbox } from "@/components/ui/Input";
import { Skeleton } from "@/components/ui/Skeleton";
import { SourceHealthDot } from "./SourceHealthDot";
import { DomainChipsInput } from "./DomainChipsInput";

type SourcesValue = SearchFormValues["sources"];

interface SourcePickerProps {
  value: SourcesValue;
  onChange: (value: SourcesValue) => void;
  errors: Record<string, string>;
  hasImage: boolean;
}

export function SourcePicker({ value, onChange, errors, hasImage }: SourcePickerProps) {
  const id = useId();
  const [filter, setFilter] = useState("");

  const tenant = useQuery({
    queryKey: [...qk.sources(), { limit: 100 }],
    queryFn: ({ signal }) => api.get<ListResponse<Source>>("/sources", { limit: 100 }, signal),
  });
  const global = useQuery({
    queryKey: [...qk.globalSources(), { limit: 100 }],
    queryFn: ({ signal }) => api.get<ListResponse<Source>>("/sources/global", { limit: 100 }, signal),
  });

  const sites = useMemo(() => {
    const own = (tenant.data?.data ?? []).filter((s) => s.type === "site" && s.enabled);
    const ownDomains = new Set(own.map((s) => s.domain));
    // Tenant adapters take precedence on the same domain.
    const lib = (global.data?.data ?? []).filter((s) => s.type === "site" && s.enabled && !ownDomains.has(s.domain));
    return [...own, ...lib];
  }, [tenant.data, global.data]);

  const visible = sites.filter((s) => {
    const q = filter.trim().toLowerCase();
    return !q || s.name.toLowerCase().includes(q) || (s.domain ?? "").includes(q);
  });

  const set = <K extends keyof SourcesValue>(key: K, v: SourcesValue[K]) => onChange({ ...value, [key]: v });
  const toggleSource = (sourceId: string) =>
    set(
      "source_ids",
      value.source_ids.includes(sourceId) ? value.source_ids.filter((x) => x !== sourceId) : [...value.source_ids, sourceId],
    );

  const loading = tenant.isPending || global.isPending;
  const loadError = tenant.isError && global.isError;

  return (
    <fieldset className="space-y-4">
      <legend className="text-sm font-semibold text-fg">Sources</legend>

      <div className="space-y-2">
        <div className="flex flex-wrap items-end justify-between gap-2">
          <label htmlFor={`${id}-filter`} className="text-sm font-medium text-fg">
            Site adapters{" "}
            <span className="font-normal text-fg-muted">
              ({value.source_ids.length} selected{sites.length ? ` of ${sites.length}` : ""})
            </span>
          </label>
          {sites.length > 0 && (
            <div className="flex gap-2 text-xs">
              <button type="button" className="text-accent hover:underline" onClick={() => set("source_ids", sites.map((s) => s.id))}>
                Select all
              </button>
              <button type="button" className="text-accent hover:underline" onClick={() => set("source_ids", [])}>
                Clear
              </button>
            </div>
          )}
        </div>
        <input
          id={`${id}-filter`}
          type="search"
          placeholder="Filter sites…"
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          className="block w-full rounded-md border border-border bg-surface px-3 py-1.5 text-sm text-fg placeholder:text-fg-muted/70 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
        />
        <div className="max-h-56 overflow-y-auto rounded-md border border-border bg-surface" role="group" aria-label="Site adapters">
          {loading ? (
            <div className="space-y-2 p-3">
              <Skeleton className="h-4 w-2/3" />
              <Skeleton className="h-4 w-1/2" />
            </div>
          ) : loadError ? (
            <p className="p-3 text-sm text-fg-muted">Could not load sources. You can still add domains below.</p>
          ) : visible.length === 0 ? (
            <p className="p-3 text-sm text-fg-muted">{sites.length ? "No sites match the filter." : "No site adapters configured yet."}</p>
          ) : (
            <ul className="divide-y divide-border">
              {visible.map((s) => (
                <li key={s.id} className="flex items-center justify-between gap-2 px-3 py-2">
                  <Checkbox
                    checked={value.source_ids.includes(s.id)}
                    onChange={() => toggleSource(s.id)}
                    label={s.name}
                    description={`${s.domain ?? ""}${s.is_global ? " · global library" : ""}`}
                  />
                  <SourceHealthDot status={s.health_status} />
                </li>
              ))}
            </ul>
          )}
        </div>
        {errors["sources.source_ids"] && (
          <p role="alert" className="text-xs text-danger">
            {errors["sources.source_ids"]}
          </p>
        )}
      </div>

      <DomainChipsInput value={value.domains} onChange={(d) => set("domains", d)} error={errors["sources.domains"]} />

      <div className="space-y-2">
        <p className="text-sm font-medium text-fg">Search engines</p>
        <div className="grid gap-2 sm:grid-cols-2">
          <Checkbox
            checked={value.shopping}
            onChange={(e) =>
              onChange({ ...value, shopping: e.target.checked, verify_shopping: e.target.checked ? value.verify_shopping : false })
            }
            label="Shopping engine"
            description="Google Shopping via SerpAPI"
          />
          <Checkbox checked={value.web} onChange={(e) => onChange({ ...value, web: e.target.checked, open_web: e.target.checked ? value.open_web : false })} label="Web search" description="Brave web results" />
          <Checkbox
            checked={value.verify_shopping}
            disabled={!value.shopping}
            onChange={(e) => set("verify_shopping", e.target.checked)}
            label="Verify shopping results by fetching merchant page"
            description="Slower, but confirms price and stock on the retailer's site"
          />
          <Checkbox
            checked={value.open_web}
            disabled={!value.web}
            onChange={(e) => set("open_web", e.target.checked)}
            label="Open web"
            description="Allow any domain, not only selected ones"
          />
          <Checkbox
            checked={value.reverse_image}
            onChange={(e) => set("reverse_image", e.target.checked)}
            label="Reverse image"
            description={hasImage ? "Find visually similar listings" : "Needs an image or image URL"}
          />
        </div>
        {errors["sources.engines"] && (
          <p role="alert" className="text-xs text-danger">
            {errors["sources.engines"]}
          </p>
        )}
      </div>
    </fieldset>
  );
}
