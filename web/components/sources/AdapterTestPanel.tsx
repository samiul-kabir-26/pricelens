"use client";

import { useMutation } from "@tanstack/react-query";
import { useState, type FormEvent } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ExternalLink } from "@/components/ui/ExternalLink";
import { Checkbox, TextField } from "@/components/ui/Input";
import { TBody, THead, Table, Td, Th } from "@/components/ui/Table";
import { api, errorMessage } from "@/lib/api";
import { formatDuration } from "@/lib/format";
import { methodLabel } from "@/components/results/OfferParts";
import type { ItemResponse, SourceTestRequest, SourceTestResult } from "@/lib/types";

/** Selector hits are a free-form map in the spec; normalise each entry to a list of strings. */
function hitList(value: unknown): string[] {
  if (Array.isArray(value)) return value.map((v) => (typeof v === "string" ? v : JSON.stringify(v)));
  if (value === null || value === undefined || value === "") return [];
  return [typeof value === "string" ? value : JSON.stringify(value)];
}

function fieldSource(value: unknown): string {
  return typeof value === "string" && value ? methodLabel(value) : "—";
}

function display(value: unknown): string {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "object") return JSON.stringify(value);
  return String(value);
}

export function AdapterTestPanel({ sourceId }: { sourceId: string }) {
  const [mode, setMode] = useState<"url" | "query">("url");
  const [input, setInput] = useState("");
  const [inputError, setInputError] = useState<string>();
  const [render, setRender] = useState(false);

  const test = useMutation({
    mutationFn: (body: SourceTestRequest) => api.post<ItemResponse<SourceTestResult>>(`/sources/${sourceId}/test`, body),
  });

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    const value = input.trim();
    if (!value) {
      setInputError(mode === "url" ? "Enter a product URL" : "Enter a search query");
      return;
    }
    if (mode === "url") {
      try {
        const u = new URL(value);
        if (u.protocol !== "http:" && u.protocol !== "https:") throw new Error();
      } catch {
        setInputError("Enter a valid http(s) URL");
        return;
      }
    }
    setInputError(undefined);
    test.mutate(mode === "url" ? { url: value, render } : { query: value, render });
  }

  const result = test.data?.data;
  const fieldEntries = result ? Object.entries(result.fields ?? {}) : [];

  return (
    <Card>
      <CardHeader title="Test adapter" description="Dry-run the saved adapter against a product URL or a search query." />
      <CardBody className="space-y-4">
        <form onSubmit={onSubmit} noValidate className="space-y-3">
          <div role="radiogroup" aria-label="Test mode" className="inline-flex rounded-md border border-border p-0.5">
            {(["url", "query"] as const).map((m) => (
              <button
                key={m}
                type="button"
                role="radio"
                aria-checked={mode === m}
                onClick={() => {
                  setMode(m);
                  setInputError(undefined);
                }}
                className={`rounded px-3 py-1 text-sm ${mode === m ? "bg-surface-muted font-medium text-fg" : "text-fg-muted hover:text-fg"}`}
              >
                {m === "url" ? "Product URL" : "Search query"}
              </button>
            ))}
          </div>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
            <TextField
              label={mode === "url" ? "Product URL" : "Search query"}
              value={input}
              onChange={(e) => setInput(e.target.value)}
              error={inputError}
              placeholder={mode === "url" ? "https://shop.example.com/p/..." : "sony wh-1000xm5"}
              type={mode === "url" ? "url" : "text"}
              containerClassName="flex-1"
            />
            <Button type="submit" variant="primary" loading={test.isPending} className="sm:mt-6">
              Run test
            </Button>
          </div>
          <Checkbox
            label="Render with browser"
            description="Load the page in headless Chromium before extracting (slower; uses render quota)."
            checked={render}
            onChange={(e) => setRender(e.target.checked)}
          />
        </form>

        <div aria-live="polite">
          {test.isError && (
            <div role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-danger">
              {errorMessage(test.error)}
            </div>
          )}
          {result && (
            <div className="space-y-4">
              <dl className="grid grid-cols-3 gap-3 text-sm">
                <div>
                  <dt className="text-xs text-fg-muted">HTTP status</dt>
                  <dd className="font-medium">
                    <Badge tone={result.http_status && result.http_status < 400 ? "success" : "danger"}>
                      {result.http_status ?? "—"}
                    </Badge>
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-fg-muted">Timing</dt>
                  <dd className="font-medium">{formatDuration(result.timing_ms)}</dd>
                </div>
                <div>
                  <dt className="text-xs text-fg-muted">Extraction</dt>
                  <dd className="font-medium">{result.extraction_method ? methodLabel(result.extraction_method) : "—"}</dd>
                </div>
              </dl>

              {result.errors.length > 0 && (
                <div className="rounded-md border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-danger">
                  <p className="font-medium">Errors</p>
                  <ul className="mt-1 list-disc pl-5">
                    {result.errors.map((e, i) => (
                      <li key={i}>{e}</li>
                    ))}
                  </ul>
                </div>
              )}

              {fieldEntries.length > 0 && (
                <Table caption="Extracted fields">
                  <THead>
                    <tr>
                      <Th>Field</Th>
                      <Th>Value</Th>
                      <Th>Source</Th>
                    </tr>
                  </THead>
                  <TBody>
                    {fieldEntries.map(([field, value]) => (
                      <tr key={field}>
                        <Td className="font-medium">{field}</Td>
                        <Td className="break-all">{display(value)}</Td>
                        <Td className="text-fg-muted">{fieldSource(result.field_sources?.[field])}</Td>
                      </tr>
                    ))}
                  </TBody>
                </Table>
              )}

              {Object.keys(result.selector_hits ?? {}).length > 0 && (
                <div>
                  <h3 className="text-sm font-semibold">Selector hits</h3>
                  <dl className="mt-2 space-y-2 text-sm">
                    {Object.entries(result.selector_hits)
                      .map(([name, raw]) => [name, hitList(raw)] as const)
                      .map(([name, hits]) => (
                        <div key={name} className="rounded-md border border-border px-3 py-2">
                          <dt className="font-mono text-xs text-fg-muted">
                            {name} <span className="text-fg">({hits.length})</span>
                          </dt>
                          <dd className="mt-1 break-all">
                            {hits.length === 0 ? <span className="text-danger">No matches</span> : hits.slice(0, 5).join(" · ")}
                          </dd>
                        </div>
                      ))}
                  </dl>
                </div>
              )}

              {result.candidates.length > 0 && (
                <div>
                  <h3 className="text-sm font-semibold">Candidates ({result.candidates.length})</h3>
                  <ul className="mt-2 space-y-1 text-sm">
                    {result.candidates.map((url) => (
                      <li key={url} className="truncate">
                        <ExternalLink href={url} className="text-accent hover:underline">
                          {url}
                        </ExternalLink>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          )}
        </div>
      </CardBody>
    </Card>
  );
}
