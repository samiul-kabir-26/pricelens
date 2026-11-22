"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useRef, useState, type FormEvent } from "react";
import { api, ApiError, errorMessage, newIdempotencyKey } from "@/lib/api";
import {
  defaultSearchFormValues,
  searchFormSchema,
  toCreateSearchFormData,
  toCreateSearchRequest,
  zodErrorToFieldErrors,
  type SearchFormValues,
} from "@/lib/schemas";
import type { ItemResponse, Search } from "@/lib/types";
import { Button } from "@/components/ui/Button";
import { Card, CardBody } from "@/components/ui/Card";
import { TextField } from "@/components/ui/Input";
import { ImageDropzone } from "./ImageDropzone";
import { OptionsPanel } from "./OptionsPanel";
import { SourcePicker } from "./SourcePicker";

/** Field keys rendered by the form; other server error keys are shown at the top. */
const KNOWN_FIELDS = new Set([
  "title",
  "brand",
  "gtin",
  "mpn",
  "image",
  "image_url",
  "reference_url",
  "sources.source_ids",
  "sources.domains",
  "sources.engines",
  "options.country",
  "options.language",
  "options.target_currency",
  "options.condition",
  "options.max_results",
  "options.min_confidence",
]);

/** Map API dot-path error keys (problem+json `errors`) onto form fields. */
export function mapServerErrors(errors: Record<string, string>): Record<string, string> {
  const out: Record<string, string> = {};
  const unknown: string[] = [];
  for (const [rawKey, message] of Object.entries(errors)) {
    let key = rawKey;
    if (/^sources\.(source_ids|domains)\.\d+/.test(key)) key = key.replace(/\.\d+.*$/, "");
    else if (/^sources\.(search_engines|reverse_image)/.test(key)) key = "sources.engines";
    else if (key === "sources") key = "sources.engines";
    if (KNOWN_FIELDS.has(key)) {
      if (!out[key]) out[key] = message;
    } else {
      unknown.push(message);
    }
  }
  if (unknown.length) out.form = unknown.join(" ");
  return out;
}

interface SearchFormProps {
  initialValues?: SearchFormValues;
}

export function SearchForm({ initialValues = defaultSearchFormValues }: SearchFormProps) {
  const router = useRouter();
  const qc = useQueryClient();
  const [values, setValues] = useState<SearchFormValues>(initialValues);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const idempotencyKey = useRef<string | null>(null);
  const summaryRef = useRef<HTMLDivElement>(null);

  const mutation = useMutation({
    mutationFn: (v: SearchFormValues) => {
      idempotencyKey.current ??= newIdempotencyKey();
      const body = v.image ? toCreateSearchFormData(v) : toCreateSearchRequest(v);
      return api.post<ItemResponse<Search>>("/searches", body, { idempotencyKey: idempotencyKey.current });
    },
    onSuccess: (res) => {
      idempotencyKey.current = null;
      void qc.invalidateQueries({ queryKey: ["searches"] });
      router.push(`/searches/${res.data.id}`);
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 422) {
        idempotencyKey.current = null;
        setErrors(mapServerErrors(err.fieldErrors));
      } else {
        if (err instanceof ApiError && err.status >= 400 && err.status < 500) idempotencyKey.current = null;
        setErrors({ form: errorMessage(err) });
      }
      requestAnimationFrame(() => summaryRef.current?.focus());
    },
  });

  const update = <K extends keyof SearchFormValues>(key: K, v: SearchFormValues[K]) => {
    setValues((prev) => ({ ...prev, [key]: v }));
    setErrors((prev) => {
      if (!prev[key as string] && !prev.form) return prev;
      const next = { ...prev };
      delete next[key as string];
      delete next.form;
      return next;
    });
  };

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    const parsed = searchFormSchema.safeParse(values);
    if (!parsed.success) {
      setErrors(zodErrorToFieldErrors(parsed.error));
      requestAnimationFrame(() => summaryRef.current?.focus());
      return;
    }
    setErrors({});
    mutation.mutate(values);
  };

  const errorCount = Object.keys(errors).length;

  return (
    <form onSubmit={onSubmit} noValidate aria-label="New price search" className="space-y-6">
      <div ref={summaryRef} tabIndex={-1} className="outline-none" aria-live="assertive">
        {errorCount > 0 && (
          <div role="alert" className="rounded-md border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger">
            {errors.form ? (
              <p>{errors.form}</p>
            ) : (
              <p>
                Please fix {errorCount === 1 ? "the highlighted field" : `${errorCount} highlighted fields`} before searching.
              </p>
            )}
          </div>
        )}
      </div>

      <Card>
        <CardBody className="space-y-5">
          <fieldset className="space-y-4">
            <legend className="text-sm font-semibold text-fg">Product</legend>
            <TextField
              label="Title"
              placeholder="Sony WH-1000XM5 Wireless Noise Cancelling Headphones Black"
              value={values.title}
              onChange={(e) => update("title", e.target.value)}
              error={errors.title}
              autoComplete="off"
            />
            <div className="grid gap-4 sm:grid-cols-3">
              <TextField
                label="GTIN / EAN / UPC"
                inputMode="numeric"
                placeholder="0027242923782"
                value={values.gtin}
                onChange={(e) => update("gtin", e.target.value)}
                error={errors.gtin}
                autoComplete="off"
              />
              <TextField
                label="MPN"
                placeholder="WH1000XM5/B"
                value={values.mpn}
                onChange={(e) => update("mpn", e.target.value)}
                error={errors.mpn}
                autoComplete="off"
              />
              <TextField
                label="Brand"
                placeholder="Sony"
                value={values.brand}
                onChange={(e) => update("brand", e.target.value)}
                error={errors.brand}
                autoComplete="off"
              />
            </div>
            <TextField
              label="Reference URL"
              type="url"
              inputMode="url"
              placeholder="https://shop.example.com/p/sony-wh-1000xm5-black"
              hint="A product page to extract identifiers from."
              value={values.reference_url}
              onChange={(e) => update("reference_url", e.target.value)}
              error={errors.reference_url}
            />
            <ImageDropzone
              file={values.image}
              onFileChange={(f) => update("image", f)}
              imageUrl={values.image_url}
              onImageUrlChange={(u) => update("image_url", u)}
              fileError={errors.image}
              urlError={errors.image_url}
            />
            <p className="text-xs text-fg-muted">Provide at least one of title, image, GTIN, MPN or reference URL.</p>
          </fieldset>
        </CardBody>
      </Card>

      <Card>
        <CardBody>
          <SourcePicker
            value={values.sources}
            onChange={(s) => update("sources", s)}
            errors={errors}
            hasImage={values.image !== null || values.image_url.trim() !== ""}
          />
        </CardBody>
      </Card>

      <Card>
        <CardBody>
          <OptionsPanel value={values.options} onChange={(o) => update("options", o)} errors={errors} />
        </CardBody>
      </Card>

      <div className="flex flex-wrap items-center justify-end gap-3">
        <Button
          variant="ghost"
          onClick={() => {
            setValues(initialValues);
            setErrors({});
          }}
        >
          Reset
        </Button>
        <Button type="submit" variant="primary" loading={mutation.isPending}>
          {mutation.isPending ? "Starting search…" : "Search prices"}
        </Button>
      </div>
    </form>
  );
}
