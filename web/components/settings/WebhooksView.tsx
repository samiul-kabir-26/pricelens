"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Fragment, useState, type FormEvent } from "react";
import { Badge, type Tone } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Drawer, Modal } from "@/components/ui/Drawer";
import { EmptyState, ErrorState } from "@/components/ui/EmptyState";
import { Checkbox, Switch, TextField } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Skeleton } from "@/components/ui/Skeleton";
import { TBody, THead, Table, Td, Th } from "@/components/ui/Table";
import { useToast } from "@/components/ui/Toast";
import { ApiError, api, errorMessage } from "@/lib/api";
import { formatDateTime, formatRelative, humanize } from "@/lib/format";
import { qk } from "@/lib/query-keys";
import { webhookFormSchema, zodErrorToFieldErrors, type WebhookFormValues } from "@/lib/schemas";
import {
  ALL_WEBHOOK_EVENTS,
  WEBHOOK_EVENTS,
  type ItemResponse,
  type ListResponse,
  type Webhook,
  type WebhookDelivery,
  type WebhookWrite,
} from "@/lib/types";

/** "All events" for a `*` subscription, else the comma-separated list. */
export function describeEvents(events: readonly string[]): string {
  return events.includes(ALL_WEBHOOK_EVENTS) ? "All events (*)" : events.join(", ");
}

export function WebhooksView() {
  const qc = useQueryClient();
  const toast = useToast();
  const [creating, setCreating] = useState(false);
  const [logFor, setLogFor] = useState<Webhook | null>(null);
  const [rotated, setRotated] = useState<{ url: string; secret: string } | null>(null);

  const query = useQuery({
    queryKey: qk.webhooks(),
    queryFn: ({ signal }) => api.get<ListResponse<Webhook>>("/webhooks", { limit: 100 }, signal),
  });

  const toggle = useMutation({
    mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) => api.patch<ItemResponse<Webhook>>(`/webhooks/${id}`, { enabled }),
    onSuccess: () => qc.invalidateQueries({ queryKey: qk.webhooks() }),
    onError: (err) => toast.error("Could not update webhook", errorMessage(err)),
  });

  const remove = useMutation({
    mutationFn: (id: string) => api.delete(`/webhooks/${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: qk.webhooks() });
      toast.success("Webhook deleted");
    },
    onError: (err) => toast.error("Could not delete webhook", errorMessage(err)),
  });

  const rotate = useMutation({
    mutationFn: (id: string) => api.patch<ItemResponse<Webhook>>(`/webhooks/${id}`, { rotate_secret: true } satisfies WebhookWrite),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: qk.webhooks() });
      if (res.data.secret) setRotated({ url: res.data.url, secret: res.data.secret });
      else toast.success("Secret rotated");
    },
    onError: (err) => toast.error("Could not rotate secret", errorMessage(err)),
  });

  const test = useMutation({
    mutationFn: (id: string) => api.post<unknown>(`/webhooks/${id}/test`),
    onSuccess: (_r, id) => {
      toast.success("Ping sent", "Check the delivery log for the result.");
      qc.invalidateQueries({ queryKey: qk.deliveries(id) });
    },
    onError: (err) => toast.error("Could not send ping", errorMessage(err)),
  });

  return (
    <div>
      <PageHeader
        title="Webhooks"
        description="Signed event deliveries (X-PriceLens-Signature: HMAC-SHA256). Retries with backoff for ~24 h."
        actions={
          <Button variant="primary" onClick={() => setCreating(true)}>
            Add endpoint
          </Button>
        }
      />
      {query.isPending ? (
        <div className="space-y-2" role="status" aria-label="Loading webhooks">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
        </div>
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => query.refetch()} />
      ) : query.data.data.length === 0 ? (
        <EmptyState title="No webhook endpoints" description="Add an HTTPS endpoint to receive search, monitor and alert events." />
      ) : (
        <ul className="space-y-3">
          {query.data.data.map((w) => (
            <li key={w.id}>
              <Card className="p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <p className="break-all font-mono text-sm text-fg">{w.url}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-fg-muted">
                      {w.disabled_at ? (
                        <Badge tone="danger">Auto-disabled {formatRelative(w.disabled_at)}</Badge>
                      ) : w.enabled ? (
                        <Badge tone="success" dot>
                          Active
                        </Badge>
                      ) : (
                        <Badge>Disabled</Badge>
                      )}
                      {w.failure_count > 0 && <Badge tone="warning">{w.failure_count} consecutive failures</Badge>}
                      <span>Created {formatDateTime(w.created_at)}</span>
                    </div>
                    <p className="mt-2 text-xs text-fg-muted">
                      <span className="sr-only">Events: </span>
                      {describeEvents(w.events)}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Switch
                      checked={w.enabled}
                      onChange={(enabled) => toggle.mutate({ id: w.id, enabled })}
                      label={`${w.enabled ? "Disable" : "Enable"} ${w.url}`}
                      hideLabel
                    />
                    <Button size="sm" onClick={() => test.mutate(w.id)} loading={test.isPending && test.variables === w.id}>
                      Send test
                    </Button>
                    <Button size="sm" onClick={() => setLogFor(w)}>
                      Deliveries
                    </Button>
                    <Button
                      size="sm"
                      onClick={() => {
                        if (window.confirm(`Rotate the signing secret for ${w.url}? The current secret stops working immediately.`))
                          rotate.mutate(w.id);
                      }}
                      loading={rotate.isPending && rotate.variables === w.id}
                    >
                      Rotate secret
                    </Button>
                    <Button
                      size="sm"
                      variant="danger"
                      onClick={() => {
                        if (window.confirm(`Delete webhook ${w.url}?`)) remove.mutate(w.id);
                      }}
                    >
                      Delete
                    </Button>
                  </div>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}
      <CreateWebhookModal open={creating} onClose={() => setCreating(false)} />
      <Modal
        open={rotated !== null}
        onClose={() => setRotated(null)}
        title="New signing secret"
        description={rotated?.url}
        footer={
          <Button variant="primary" onClick={() => setRotated(null)}>
            Done
          </Button>
        }
      >
        {rotated && <SecretReveal secret={rotated.secret} />}
      </Modal>
      <Drawer open={logFor !== null} onClose={() => setLogFor(null)} title="Delivery log" description={logFor?.url}>
        {logFor && <DeliveryLog webhookId={logFor.id} />}
      </Drawer>
    </div>
  );
}

const DELIVERY_TONE: Record<WebhookDelivery["status"], Tone> = { pending: "info", delivered: "success", failed: "danger" };

function DeliveryLog({ webhookId }: { webhookId: string }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [expanded, setExpanded] = useState<string | null>(null);
  const query = useQuery({
    queryKey: qk.deliveries(webhookId),
    queryFn: ({ signal }) =>
      api.get<ListResponse<WebhookDelivery>>(`/webhooks/${webhookId}/deliveries`, { limit: 50, include_payload: 1 }, signal),
    refetchInterval: 10_000,
  });

  const redeliver = useMutation({
    mutationFn: (deliveryId: string) => api.post<unknown>(`/webhooks/${webhookId}/deliveries/${deliveryId}/redeliver`),
    onSuccess: () => {
      toast.success("Redelivery queued");
      qc.invalidateQueries({ queryKey: qk.deliveries(webhookId) });
    },
    onError: (err) => toast.error("Could not redeliver", errorMessage(err)),
  });

  if (query.isPending) return <Skeleton className="h-48 w-full" />;
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  if (query.data.data.length === 0) return <EmptyState title="No deliveries yet" description="Send a test ping to try it." />;

  return (
    <Table caption="Webhook deliveries">
      <THead>
        <tr>
          <Th>Event</Th>
          <Th>Status</Th>
          <Th>Response</Th>
          <Th>When</Th>
          <Th>
            <span className="sr-only">Actions</span>
          </Th>
        </tr>
      </THead>
      <TBody>
        {query.data.data.map((d) => {
          const open = expanded === d.id;
          const panelId = `delivery-payload-${d.id}`;
          return (
            <Fragment key={d.id}>
              <tr>
                <Td>
                  <div className="font-mono text-xs">{d.event}</div>
                  <div className="font-mono text-[11px] text-fg-muted">{d.event_id}</div>
                  {d.payload !== undefined && (
                    <button
                      type="button"
                      aria-expanded={open}
                      aria-controls={open ? panelId : undefined}
                      onClick={() => setExpanded(open ? null : d.id)}
                      className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
                    >
                      <span aria-hidden="true" className={open ? "rotate-90 transition-transform" : "transition-transform"}>
                        ▸
                      </span>
                      {open ? "Hide payload" : "Show payload"}
                    </button>
                  )}
                </Td>
                <Td>
                  <Badge tone={DELIVERY_TONE[d.status]}>{humanize(d.status)}</Badge>
                  <div className="mt-1 text-xs text-fg-muted">
                    {d.attempts} attempt{d.attempts === 1 ? "" : "s"}
                    {d.status === "pending" && d.next_attempt_at && <> · next {formatRelative(d.next_attempt_at)}</>}
                  </div>
                </Td>
                <Td>
                  <div className="tabular-nums">{d.response_status ?? "—"}</div>
                  {d.response_body_excerpt && (
                    <details className="mt-1 text-xs">
                      <summary className="cursor-pointer text-fg-muted">Body</summary>
                      <pre className="mt-1 max-w-xs overflow-x-auto whitespace-pre-wrap break-all rounded bg-surface-muted p-2 font-mono">
                        {d.response_body_excerpt}
                      </pre>
                    </details>
                  )}
                </Td>
                <Td className="whitespace-nowrap text-xs text-fg-muted" title={formatDateTime(d.delivered_at ?? d.created_at)}>
                  {formatRelative(d.delivered_at ?? d.created_at)}
                </Td>
                <Td>
                  <Button size="sm" onClick={() => redeliver.mutate(d.id)} loading={redeliver.isPending && redeliver.variables === d.id}>
                    Redeliver
                  </Button>
                </Td>
              </tr>
              {open && d.payload !== undefined && (
                <tr id={panelId}>
                  <Td colSpan={5} className="bg-surface-muted/50">
                    <pre
                      aria-label={`Payload for ${d.event}`}
                      className="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded bg-surface-muted p-3 font-mono text-xs text-fg"
                    >
                      {JSON.stringify(d.payload, null, 2)}
                    </pre>
                  </Td>
                </tr>
              )}
            </Fragment>
          );
        })}
      </TBody>
    </Table>
  );
}

const emptyWebhook: WebhookFormValues = { url: "", events: ["search.completed", "search.failed"] };

function CreateWebhookModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [values, setValues] = useState<WebhookFormValues>(emptyWebhook);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [secret, setSecret] = useState<string | null>(null);

  const create = useMutation({
    mutationFn: (body: WebhookFormValues) => api.post<ItemResponse<Webhook>>("/webhooks", body),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: qk.webhooks() });
      setSecret(res.data.secret ?? null);
      setErrors({});
      if (!res.data.secret) close();
      toast.success("Webhook created");
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 422) {
        setErrors(Object.fromEntries(Object.entries(err.fieldErrors).map(([k, v]) => [k.split(".")[0], v])));
      } else toast.error("Could not create webhook", errorMessage(err));
    },
  });

  function close() {
    setValues(emptyWebhook);
    setErrors({});
    setSecret(null);
    onClose();
  }

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    const parsed = webhookFormSchema.safeParse(values);
    if (!parsed.success) {
      setErrors(zodErrorToFieldErrors(parsed.error));
      return;
    }
    create.mutate(parsed.data);
  }

  const allEvents = values.events.includes(ALL_WEBHOOK_EVENTS);
  const toggleEvent = (ev: string, on: boolean) =>
    setValues((v) => ({ ...v, events: on ? [...v.events, ev] : v.events.filter((x) => x !== ev) }));

  return (
    <Modal
      open={open}
      onClose={close}
      title={secret ? "Save your signing secret" : "Add webhook endpoint"}
      footer={
        secret ? (
          <Button variant="primary" onClick={close}>
            Done
          </Button>
        ) : (
          <>
            <Button onClick={close}>Cancel</Button>
            <Button type="submit" form="webhook-form" variant="primary" loading={create.isPending}>
              Create
            </Button>
          </>
        )
      }
    >
      {secret ? (
        <SecretReveal secret={secret} />
      ) : (
        <form id="webhook-form" onSubmit={onSubmit} noValidate className="space-y-4">
          <TextField
            label="Endpoint URL"
            type="url"
            value={values.url}
            onChange={(e) => setValues((v) => ({ ...v, url: e.target.value }))}
            error={errors.url}
            placeholder="https://example.com/webhooks/pricelens"
            required
          />
          <fieldset aria-describedby={errors.events ? "events-err" : undefined}>
            <legend className="text-sm font-medium">Events</legend>
            <Checkbox
              className="mt-2"
              label="All events"
              description="Subscribe with * — includes events added later."
              checked={allEvents}
              onChange={(e) => setValues((v) => ({ ...v, events: e.target.checked ? [ALL_WEBHOOK_EVENTS] : [] }))}
            />
            <div className="mt-2 grid gap-2 sm:grid-cols-2">
              {WEBHOOK_EVENTS.map((ev) => (
                <Checkbox
                  key={ev}
                  label={<span className="font-mono text-xs">{ev}</span>}
                  checked={allEvents || values.events.includes(ev)}
                  disabled={allEvents}
                  onChange={(e) => toggleEvent(ev, e.target.checked)}
                />
              ))}
            </div>
            {errors.events && (
              <p id="events-err" role="alert" className="mt-1 text-xs text-danger">
                {errors.events}
              </p>
            )}
          </fieldset>
        </form>
      )}
    </Modal>
  );
}

/** One-time secret display with copy-to-clipboard. */
function SecretReveal({ secret }: { secret: string }) {
  const toast = useToast();
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(secret);
      setCopied(true);
    } catch {
      toast.error("Copy failed", "Select the secret and copy it manually.");
    }
  }

  return (
    <div className="space-y-3">
      <p className="text-sm text-fg-muted">
        This secret is shown only once. Use it to verify the <code className="font-mono">X-PriceLens-Signature</code> header.
      </p>
      <div className="flex items-center gap-2">
        <label htmlFor="webhook-secret" className="sr-only">
          Signing secret
        </label>
        <input
          id="webhook-secret"
          readOnly
          value={secret}
          onFocus={(e) => e.currentTarget.select()}
          className="block w-full rounded-md border border-border bg-surface-muted px-3 py-2 font-mono text-xs"
        />
        <Button onClick={copy}>{copied ? "Copied" : "Copy"}</Button>
      </div>
      <p aria-live="polite" className="sr-only">
        {copied ? "Secret copied to clipboard" : ""}
      </p>
    </div>
  );
}
