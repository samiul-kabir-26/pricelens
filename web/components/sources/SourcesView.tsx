"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Modal } from "@/components/ui/Drawer";
import { EmptyState, ErrorState } from "@/components/ui/EmptyState";
import { Switch, TextField } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { SelectField } from "@/components/ui/Select";
import { Skeleton } from "@/components/ui/Skeleton";
import { TBody, THead, Table, Td, Th } from "@/components/ui/Table";
import { useToast } from "@/components/ui/Toast";
import { ApiError, api, errorMessage } from "@/lib/api";
import { cn, humanize } from "@/lib/format";
import { qk } from "@/lib/query-keys";
import { RENDER_MODES, SOURCE_TYPES, sourceFormSchema, zodErrorToFieldErrors, type SourceFormValues } from "@/lib/schemas";
import type { ItemResponse, ListResponse, Source } from "@/lib/types";
import { SourceHealthBadge } from "./SourceHealthBadge";

type Tab = "mine" | "global";

export function SourcesView() {
  const [tab, setTab] = useState<Tab>("mine");
  const [creating, setCreating] = useState(false);

  return (
    <div>
      <PageHeader
        title="Sources"
        description="Site adapters, search engines and feeds used to discover offers."
        actions={
          <Button variant="primary" onClick={() => setCreating(true)}>
            New source
          </Button>
        }
      />
      <div role="tablist" aria-label="Source lists" className="mb-4 flex gap-1 border-b border-border">
        {(
          [
            ["mine", "Your sources"],
            ["global", "Global library"],
          ] as const
        ).map(([key, label]) => (
          <button
            key={key}
            role="tab"
            id={`tab-${key}`}
            aria-selected={tab === key}
            aria-controls={`panel-${key}`}
            tabIndex={tab === key ? 0 : -1}
            onClick={() => setTab(key)}
            onKeyDown={(e) => {
              if (e.key === "ArrowRight" || e.key === "ArrowLeft") {
                const next = key === "mine" ? "global" : "mine";
                setTab(next);
                document.getElementById(`tab-${next}`)?.focus();
              }
            }}
            className={cn(
              "-mb-px border-b-2 px-3 py-2 text-sm",
              tab === key ? "border-accent font-medium text-fg" : "border-transparent text-fg-muted hover:text-fg",
            )}
          >
            {label}
          </button>
        ))}
      </div>
      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`}>
        <SourceList global={tab === "global"} />
      </div>
      <NewSourceModal open={creating} onClose={() => setCreating(false)} />
    </div>
  );
}

function SourceList({ global }: { global: boolean }) {
  const qc = useQueryClient();
  const router = useRouter();
  const toast = useToast();
  const key = global ? qk.globalSources() : qk.sources();
  const query = useQuery({
    queryKey: key,
    queryFn: ({ signal }) => api.get<ListResponse<Source>>(global ? "/sources/global" : "/sources", { limit: 100 }, signal),
  });

  const toggle = useMutation({
    mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) =>
      api.patch<ItemResponse<Source>>(`/sources/${id}`, { enabled }),
    onMutate: async ({ id, enabled }) => {
      await qc.cancelQueries({ queryKey: key });
      const prev = qc.getQueryData<ListResponse<Source>>(key);
      qc.setQueryData<ListResponse<Source>>(key, (old) =>
        old ? { ...old, data: old.data.map((s) => (s.id === id ? { ...s, enabled } : s)) } : old,
      );
      return { prev };
    },
    onError: (err, _vars, ctx) => {
      if (ctx?.prev) qc.setQueryData(key, ctx.prev);
      toast.error("Could not update source", errorMessage(err));
    },
    onSettled: () => qc.invalidateQueries({ queryKey: qk.sources() }),
  });

  const fork = useMutation({
    mutationFn: (id: string) => api.post<ItemResponse<Source>>(`/sources/${id}/fork`),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: qk.sources() });
      toast.success("Adapter forked");
      router.push(`/sources/${res.data.id}`);
    },
    onError: (err) => toast.error("Could not fork adapter", errorMessage(err)),
  });

  const remove = useMutation({
    mutationFn: (id: string) => api.delete(`/sources/${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: qk.sources() });
      toast.success("Source deleted");
    },
    onError: (err) => toast.error("Could not delete source", errorMessage(err)),
  });

  if (query.isPending) {
    return (
      <div className="space-y-2" role="status" aria-label="Loading sources">
        {Array.from({ length: 4 }, (_, i) => (
          <Skeleton key={i} className="h-14 w-full" />
        ))}
      </div>
    );
  }
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;

  const sources = query.data.data;
  if (sources.length === 0) {
    return (
      <EmptyState
        title={global ? "The global library is empty" : "No sources yet"}
        description={global ? undefined : "Create an adapter or fork one from the global library."}
      />
    );
  }

  const actions = (s: Source) => (
    <div className="flex flex-wrap items-center gap-2">
      {global ? (
        <Button size="sm" onClick={() => fork.mutate(s.id)} loading={fork.isPending && fork.variables === s.id}>
          Fork
        </Button>
      ) : (
        <Button
          size="sm"
          variant="danger"
          onClick={() => {
            if (window.confirm(`Delete source "${s.name}"? This cannot be undone.`)) remove.mutate(s.id);
          }}
          loading={remove.isPending && remove.variables === s.id}
        >
          Delete
        </Button>
      )}
    </div>
  );

  return (
    <>
      {/* Mobile: cards */}
      <ul className="space-y-3 md:hidden">
        {sources.map((s) => (
          <li key={s.id}>
            <Card className="p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <Link href={`/sources/${s.id}`} className="font-medium text-fg hover:underline">
                    {s.name}
                  </Link>
                  <p className="truncate text-sm text-fg-muted">
                    {humanize(s.type)}
                    {s.domain ? ` · ${s.domain}` : ""}
                  </p>
                </div>
                <SourceHealthBadge status={s.health_status} />
              </div>
              <div className="mt-3 flex items-center justify-between gap-3">
                <Switch
                  checked={s.enabled}
                  onChange={(enabled) => toggle.mutate({ id: s.id, enabled })}
                  label={s.enabled ? "Enabled" : "Disabled"}
                />
                {actions(s)}
              </div>
            </Card>
          </li>
        ))}
      </ul>
      {/* Desktop: table */}
      <Card className="hidden md:block">
        <Table caption={global ? "Global adapter library" : "Your sources"}>
          <THead>
            <tr>
              <Th>Name</Th>
              <Th>Type</Th>
              <Th>Domain</Th>
              <Th>Country</Th>
              <Th>Health</Th>
              <Th>Enabled</Th>
              <Th>
                <span className="sr-only">Actions</span>
              </Th>
            </tr>
          </THead>
          <TBody>
            {sources.map((s) => (
              <tr key={s.id}>
                <Td>
                  <Link href={`/sources/${s.id}`} className="font-medium text-fg hover:underline">
                    {s.name}
                  </Link>
                  {s.forked_from_id && <span className="ml-2 text-xs text-fg-muted">fork</span>}
                </Td>
                <Td className="text-fg-muted">{humanize(s.type)}</Td>
                <Td className="font-mono text-xs">{s.domain ?? "—"}</Td>
                <Td>{s.country ?? "—"}</Td>
                <Td>
                  <SourceHealthBadge status={s.health_status} />
                </Td>
                <Td>
                  <Switch
                    checked={s.enabled}
                    onChange={(enabled) => toggle.mutate({ id: s.id, enabled })}
                    label={`${s.enabled ? "Disable" : "Enable"} ${s.name}`}
                    hideLabel
                  />
                </Td>
                <Td className="text-right">{actions(s)}</Td>
              </tr>
            ))}
          </TBody>
        </Table>
      </Card>
    </>
  );
}

const emptySource: SourceFormValues = {
  name: "",
  type: "site",
  domain: "",
  country: "US",
  currency: "USD",
  render: "auto",
  enabled: true,
};

function NewSourceModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const router = useRouter();
  const toast = useToast();
  const [values, setValues] = useState<SourceFormValues>(emptySource);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = <K extends keyof SourceFormValues>(k: K, v: SourceFormValues[K]) => setValues((x) => ({ ...x, [k]: v }));

  const create = useMutation({
    mutationFn: (body: SourceFormValues) =>
      api.post<ItemResponse<Source>>("/sources", {
        ...body,
        domain: body.domain || null,
        country: body.country || null,
        currency: body.currency || null,
        config: {},
      }),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: qk.sources() });
      toast.success("Source created", "Configure selectors and run a test.");
      setValues(emptySource);
      onClose();
      router.push(`/sources/${res.data.id}`);
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 422) setErrors(err.fieldErrors);
      else toast.error("Could not create source", errorMessage(err));
    },
  });

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    const parsed = sourceFormSchema.safeParse(values);
    if (!parsed.success) {
      setErrors(zodErrorToFieldErrors(parsed.error));
      return;
    }
    setErrors({});
    create.mutate(parsed.data);
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="New source"
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" form="new-source-form" variant="primary" loading={create.isPending}>
            Create
          </Button>
        </>
      }
    >
      <form id="new-source-form" onSubmit={onSubmit} noValidate className="grid gap-4 sm:grid-cols-2">
        <TextField label="Name" value={values.name} onChange={(e) => set("name", e.target.value)} error={errors.name} required containerClassName="sm:col-span-2" />
        <SelectField
          label="Type"
          options={SOURCE_TYPES.map((t) => ({ value: t, label: humanize(t) }))}
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
        <TextField label="Country" value={values.country} onChange={(e) => set("country", e.target.value.toUpperCase())} error={errors.country} maxLength={2} />
        <TextField label="Currency" value={values.currency} onChange={(e) => set("currency", e.target.value.toUpperCase())} error={errors.currency} maxLength={3} />
        <SelectField
          label="Render"
          options={RENDER_MODES.map((r) => ({ value: r, label: humanize(r) }))}
          value={values.render}
          onChange={(e) => set("render", e.target.value as SourceFormValues["render"])}
          error={errors.render}
          containerClassName="sm:col-span-2"
        />
        {errors.config && <p className="text-sm text-danger sm:col-span-2">{errors.config}</p>}
      </form>
    </Modal>
  );
}
