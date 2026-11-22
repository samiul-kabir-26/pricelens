"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Checkbox, TextField } from "@/components/ui/Input";
import { useToast } from "@/components/ui/Toast";
import { ApiError, api, errorMessage } from "@/lib/api";
import { alertRulesSchema, zodErrorToFieldErrors, type AlertRulesFormValues } from "@/lib/schemas";
import type { AlertRules, ItemResponse, Monitor } from "@/lib/types";

function toValues(r: AlertRules): AlertRulesFormValues {
  return {
    price_drop_percent: r.price_drop_percent ?? null,
    price_increase_percent: r.price_increase_percent ?? null,
    target_price: r.target_price === null || r.target_price === undefined ? "" : String(r.target_price),
    map_enforcement: r.map_enforcement ?? false,
    availability_changes: r.availability_changes ?? false,
    new_sellers: r.new_sellers ?? false,
    cooldown_minutes: r.cooldown_minutes ?? 720,
  };
}

const numOrNull = (v: string) => (v.trim() === "" ? null : Number(v));

/** Maps API error keys ("alert_rules.target_price") onto local field names. */
function stripPrefix(errors: Record<string, string>): Record<string, string> {
  return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k.replace(/^alert_rules\./, ""), v]));
}

export function AlertRulesForm({ monitor }: { monitor: Monitor }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [values, setValues] = useState<AlertRulesFormValues>(() => toValues(monitor.alert_rules ?? {}));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = <K extends keyof AlertRulesFormValues>(k: K, v: AlertRulesFormValues[K]) => setValues((x) => ({ ...x, [k]: v }));

  const save = useMutation({
    mutationFn: (alert_rules: AlertRules) => api.patch<ItemResponse<Monitor>>(`/monitors/${monitor.id}`, { alert_rules }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["monitors"] });
      setErrors({});
      toast.success("Alert rules saved");
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 422) setErrors(stripPrefix(err.fieldErrors));
      toast.error("Could not save alert rules", errorMessage(err));
    },
  });

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    const parsed = alertRulesSchema.safeParse(values);
    if (!parsed.success) {
      setErrors(zodErrorToFieldErrors(parsed.error));
      return;
    }
    const v = parsed.data;
    save.mutate({ ...v, target_price: v.target_price === "" ? null : Number(v.target_price).toFixed(2) });
  }

  const idp = `ar-${monitor.id}`;
  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4" aria-labelledby={`${idp}-title`}>
      <h3 id={`${idp}-title`} className="text-sm font-semibold">
        Alert rules
      </h3>
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          label="Price drop (%)"
          type="number"
          inputMode="decimal"
          min={0}
          max={100}
          value={values.price_drop_percent ?? ""}
          onChange={(e) => set("price_drop_percent", numOrNull(e.target.value))}
          error={errors.price_drop_percent}
          hint="Alert when price falls by at least this much."
        />
        <TextField
          label="Price increase (%)"
          type="number"
          inputMode="decimal"
          min={0}
          max={100}
          value={values.price_increase_percent ?? ""}
          onChange={(e) => set("price_increase_percent", numOrNull(e.target.value))}
          error={errors.price_increase_percent}
        />
        <TextField
          label="Target price"
          inputMode="decimal"
          value={values.target_price}
          onChange={(e) => set("target_price", e.target.value)}
          error={errors.target_price}
          placeholder="299.00"
          hint="Alert when any site goes at or below this price."
        />
        <TextField
          label="Cooldown (minutes)"
          type="number"
          min={0}
          value={values.cooldown_minutes}
          onChange={(e) => set("cooldown_minutes", e.target.value === "" ? 0 : Number(e.target.value))}
          error={errors.cooldown_minutes}
          hint="Suppress repeat alerts per rule and site."
        />
      </div>
      <div className="space-y-2">
        <Checkbox label="MAP enforcement" description="Alert when an advertised price is below the product's MAP." checked={values.map_enforcement} onChange={(e) => set("map_enforcement", e.target.checked)} />
        <Checkbox label="Availability changes" description="Back in stock / out of stock transitions." checked={values.availability_changes} onChange={(e) => set("availability_changes", e.target.checked)} />
        <Checkbox label="New sellers" description="A new domain or seller appears." checked={values.new_sellers} onChange={(e) => set("new_sellers", e.target.checked)} />
      </div>
      <div className="flex justify-end">
        <Button type="submit" variant="primary" size="sm" loading={save.isPending}>
          Save rules
        </Button>
      </div>
    </form>
  );
}
