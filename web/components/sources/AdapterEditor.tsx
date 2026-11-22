"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Checkbox, TextAreaField, TextField } from "@/components/ui/Input";
import { SelectField } from "@/components/ui/Select";
import { useToast } from "@/components/ui/Toast";
import { ApiError, api, errorMessage } from "@/lib/api";
import { humanize } from "@/lib/format";
import { qk } from "@/lib/query-keys";
import { RENDER_MODES, SOURCE_TYPES, sourceFormSchema, zodErrorToFieldErrors, type SourceFormValues } from "@/lib/schemas";
import type { ItemResponse, Source, SourceConfig, SourceWrite } from "@/lib/types";

const typeOptions = SOURCE_TYPES.map((t) => ({ value: t, label: humanize(t) }));
const renderOptions = RENDER_MODES.map((r) => ({ value: r, label: humanize(r) }));

function toValues(s: Source): SourceFormValues {
  return {
    name: s.name,
    type: s.type,
    domain: s.domain ?? "",
    country: s.country ?? "",
    currency: s.currency ?? "",
    render: s.render,
    enabled: s.enabled,
  };
}

export function AdapterEditor({ source }: { source: Source }) {
  const readOnly = source.is_global;
  const qc = useQueryClient();
  const router = useRouter();
  const toast = useToast();
  const [values, setValues] = useState<SourceFormValues>(() => toValues(source));
  const [configText, setConfigText] = useState(() => JSON.stringify(source.config ?? {}, null, 2));
  const [errors, setErrors] = useState<Record<string, string>>({});

  const set = <K extends keyof SourceFormValues>(key: K, value: SourceFormValues[K]) =>
    setValues((v) => ({ ...v, [key]: value }));

  const save = useMutation({
    mutationFn: (body: SourceWrite) => api.patch<ItemResponse<Source>>(`/sources/${source.id}`, body),
    onSuccess: (res) => {
      qc.setQueryData(qk.source(source.id), res);
      qc.invalidateQueries({ queryKey: qk.sources() });
      setErrors({});
      toast.success("Adapter saved");
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 422) setErrors(err.fieldErrors);
      toast.error("Could not save adapter", errorMessage(err));
    },
  });

  const fork = useMutation({
    mutationFn: () => api.post<ItemResponse<Source>>(`/sources/${source.id}/fork`),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: qk.sources() });
      toast.success("Adapter forked", "You can now edit your own copy.");
      router.push(`/sources/${res.data.id}`);
    },
    onError: (err) => toast.error("Could not fork adapter", errorMessage(err)),
  });

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    const parsed = sourceFormSchema.safeParse(values);
    const next: Record<string, string> = parsed.success ? {} : zodErrorToFieldErrors(parsed.error);
    let config: SourceConfig | null = null;
    try {
      const raw: unknown = JSON.parse(configText || "{}");
      if (!raw || typeof raw !== "object" || Array.isArray(raw)) throw new Error("Config must be a JSON object");
      config = raw as SourceConfig;
    } catch (err) {
      next.config = err instanceof SyntaxError ? `Invalid JSON: ${err.message}` : errorMessage(err);
    }
    setErrors(next);
    if (!parsed.success || !config) return;
    const v = parsed.data;
    save.mutate({
      name: v.name,
      type: v.type,
      domain: v.domain || null,
      country: v.country || null,
      currency: v.currency || null,
      render: v.render,
      enabled: v.enabled,
      config,
    });
  }

  return (
    <Card>
      <CardHeader
        title="Adapter"
        description={readOnly ? "Global adapters are maintained by PriceLens. Fork one to customise it." : "Metadata and extraction config (spec §7)."}
        actions={
          readOnly ? (
            <Button variant="primary" size="sm" onClick={() => fork.mutate()} loading={fork.isPending}>
              Fork to edit
            </Button>
          ) : null
        }
      />
      <CardBody>
        <form onSubmit={onSubmit} noValidate className="space-y-4">
          <fieldset disabled={readOnly} className="grid gap-4 sm:grid-cols-2">
            <legend className="sr-only">Adapter metadata</legend>
            <TextField label="Name" value={values.name} onChange={(e) => set("name", e.target.value)} error={errors.name} required />
            <SelectField
              label="Type"
              options={typeOptions}
              value={values.type}
              onChange={(e) => set("type", e.target.value as SourceFormValues["type"])}
              error={errors.type}
            />
            <TextField
              label="Domain"
              value={values.domain}
              onChange={(e) => set("domain", e.target.value.trim().toLowerCase())}
              error={errors.domain}
              placeholder="shop.example.com"
              autoComplete="off"
            />
            <SelectField
              label="Render"
              options={renderOptions}
              value={values.render}
              onChange={(e) => set("render", e.target.value as SourceFormValues["render"])}
              error={errors.render}
              hint="Use headless rendering never, when needed, or always."
            />
            <TextField
              label="Country"
              value={values.country}
              onChange={(e) => set("country", e.target.value.toUpperCase())}
              error={errors.country}
              maxLength={2}
              placeholder="US"
            />
            <TextField
              label="Currency"
              value={values.currency}
              onChange={(e) => set("currency", e.target.value.toUpperCase())}
              error={errors.currency}
              maxLength={3}
              placeholder="USD"
            />
            <Checkbox
              label="Enabled"
              description="Disabled sources are skipped in new searches."
              checked={values.enabled}
              onChange={(e) => set("enabled", e.target.checked)}
              className="sm:col-span-2"
            />
          </fieldset>
          <TextAreaField
            label="Config (JSON)"
            value={configText}
            onChange={(e) => setConfigText(e.target.value)}
            error={errors.config}
            hint="Selectors use CSS by default, an xpath: prefix for XPath and an @attr suffix to read attributes."
            readOnly={readOnly}
            spellCheck={false}
            rows={18}
            className="font-mono text-xs"
          />
          {!readOnly && (
            <div className="flex justify-end">
              <Button type="submit" variant="primary" loading={save.isPending}>
                Save adapter
              </Button>
            </div>
          )}
        </form>
      </CardBody>
    </Card>
  );
}
