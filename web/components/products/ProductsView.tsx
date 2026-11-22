"use client";

import { useInfiniteQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useRef, useState, type FormEvent } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Modal } from "@/components/ui/Drawer";
import { EmptyState, ErrorState } from "@/components/ui/EmptyState";
import { Checkbox, TextField } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Skeleton } from "@/components/ui/Skeleton";
import { TBody, THead, Table, Td, Th } from "@/components/ui/Table";
import { useToast } from "@/components/ui/Toast";
import { ApiError, api, errorMessage } from "@/lib/api";
import { normalizeGtin } from "@/lib/gtin";
import { compareMoney, formatMoney } from "@/lib/money";
import { qk } from "@/lib/query-keys";
import { trackedProductFormSchema, zodErrorToFieldErrors, type TrackedProductFormValues } from "@/lib/schemas";
import type { ItemResponse, LatestPrice, ListResponse, TrackedProduct, TrackedProductCreate } from "@/lib/types";

const PAGE_SIZE = 25;

function cheapest(p: TrackedProduct) {
  const value = (l: LatestPrice) => l.converted_total ?? l.total ?? l.price;
  return [...(p.latest_prices ?? [])].sort((a, b) => compareMoney(value(a), value(b)))[0];
}

export function ProductsView() {
  const qc = useQueryClient();
  const toast = useToast();
  const [creating, setCreating] = useState(false);
  const [importing, setImporting] = useState(false);
  const [showArchived, setShowArchived] = useState(false);
  const filters = { archived: showArchived ? "1" : undefined };

  const query = useInfiniteQuery({
    queryKey: qk.products(filters),
    initialPageParam: null as string | null,
    queryFn: ({ pageParam, signal }) =>
      api.get<ListResponse<TrackedProduct>>(
        "/tracked-products",
        { limit: PAGE_SIZE, cursor: pageParam, archived: filters.archived },
        signal,
      ),
    getNextPageParam: (last) => last.meta.next_cursor,
  });

  const archive = useMutation({
    mutationFn: ({ id, archived }: { id: string; archived: boolean }) =>
      api.patch<ItemResponse<TrackedProduct>>(`/tracked-products/${id}`, { archived }),
    onSuccess: (_res, vars) => {
      qc.invalidateQueries({ queryKey: ["tracked-products"] });
      toast.success(vars.archived ? "Product archived" : "Product restored");
    },
    onError: (err) => toast.error("Could not update product", errorMessage(err)),
  });

  const remove = useMutation({
    mutationFn: (id: string) => api.delete(`/tracked-products/${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["tracked-products"] });
      toast.success("Product deleted");
    },
    onError: (err) => toast.error("Could not delete product", errorMessage(err)),
  });

  const products = query.data?.pages.flatMap((p) => p.data) ?? [];
  const visible = showArchived ? products : products.filter((p) => !p.archived_at);

  const rowActions = (p: TrackedProduct) => (
    <div className="flex flex-wrap justify-end gap-2">
      <Button size="sm" onClick={() => archive.mutate({ id: p.id, archived: !p.archived_at })}>
        {p.archived_at ? "Restore" : "Archive"}
      </Button>
      <Button
        size="sm"
        variant="danger"
        onClick={() => {
          if (window.confirm(`Delete "${p.title}" and its history?`)) remove.mutate(p.id);
        }}
      >
        Delete
      </Button>
    </div>
  );

  return (
    <div>
      <PageHeader
        title="Tracked products"
        description="Catalog items monitored on a schedule, with price history and alerts."
        actions={
          <>
            <Button onClick={() => setImporting(true)}>Import CSV</Button>
            <Button variant="primary" onClick={() => setCreating(true)}>
              Add product
            </Button>
          </>
        }
      />
      <Checkbox
        className="mb-4"
        label="Show archived"
        checked={showArchived}
        onChange={(e) => setShowArchived(e.target.checked)}
      />

      {query.isPending ? (
        <div className="space-y-2" role="status" aria-label="Loading products">
          {Array.from({ length: 5 }, (_, i) => (
            <Skeleton key={i} className="h-14 w-full" />
          ))}
        </div>
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => query.refetch()} />
      ) : visible.length === 0 ? (
        <EmptyState
          title="No tracked products"
          description='Add one here, import a CSV, or use "Track this product" on a search result.'
        />
      ) : (
        <>
          <ul className="space-y-3 md:hidden">
            {visible.map((p) => {
              const best = cheapest(p);
              return (
                <li key={p.id}>
                  <Card className="p-4">
                    <Link href={`/products/${p.id}`} className="font-medium text-fg hover:underline">
                      {p.title}
                    </Link>
                    <p className="text-sm text-fg-muted">
                      {[p.brand, p.gtin, p.mpn].filter(Boolean).join(" · ") || "No identifiers"}
                    </p>
                    <p className="mt-2 text-sm">
                      Lowest: <span className="font-medium">{best ? formatMoney(best.total ?? best.price) : "—"}</span>
                      {best && <span className="text-fg-muted"> at {best.site_domain}</span>}
                    </p>
                    <div className="mt-3">{rowActions(p)}</div>
                  </Card>
                </li>
              );
            })}
          </ul>
          <Card className="hidden md:block">
            <Table caption="Tracked products">
              <THead>
                <tr>
                  <Th>Product</Th>
                  <Th>Identifiers</Th>
                  <Th className="text-right">Reference</Th>
                  <Th className="text-right">MAP</Th>
                  <Th className="text-right">Lowest now</Th>
                  <Th>Sites</Th>
                  <Th>
                    <span className="sr-only">Actions</span>
                  </Th>
                </tr>
              </THead>
              <TBody>
                {visible.map((p) => {
                  const best = cheapest(p);
                  return (
                    <tr key={p.id}>
                      <Td>
                        <div className="flex items-center gap-3">
                          {p.image_url ? (
                            // eslint-disable-next-line @next/next/no-img-element
                            <img src={p.image_url} alt="" className="h-10 w-10 rounded border border-border object-contain" loading="lazy" />
                          ) : (
                            <div className="h-10 w-10 rounded border border-border bg-surface-muted" aria-hidden="true" />
                          )}
                          <div className="min-w-0">
                            <Link href={`/products/${p.id}`} className="font-medium text-fg hover:underline">
                              {p.title}
                            </Link>
                            <div className="text-xs text-fg-muted">
                              {p.brand ?? ""}
                              {p.archived_at && (
                                <Badge className="ml-2" tone="neutral">
                                  Archived
                                </Badge>
                              )}
                            </div>
                          </div>
                        </div>
                      </Td>
                      <Td className="font-mono text-xs text-fg-muted">
                        {p.gtin && <div>GTIN {p.gtin}</div>}
                        {p.mpn && <div>MPN {p.mpn}</div>}
                        {p.external_ref && <div>Ref {p.external_ref}</div>}
                      </Td>
                      <Td className="text-right tabular-nums">{formatMoney(p.reference_price)}</Td>
                      <Td className="text-right tabular-nums">{formatMoney(p.map_price)}</Td>
                      <Td className="text-right tabular-nums">
                        {best ? (
                          <>
                            <div className="font-medium">{formatMoney(best.total ?? best.price)}</div>
                            <div className="text-xs text-fg-muted">{best.site_domain}</div>
                          </>
                        ) : (
                          "—"
                        )}
                      </Td>
                      <Td>{p.latest_prices?.length ?? 0}</Td>
                      <Td>{rowActions(p)}</Td>
                    </tr>
                  );
                })}
              </TBody>
            </Table>
          </Card>
          {query.hasNextPage && (
            <div className="mt-4 flex justify-center">
              <Button onClick={() => query.fetchNextPage()} loading={query.isFetchingNextPage}>
                Load more
              </Button>
            </div>
          )}
        </>
      )}

      <CreateProductModal open={creating} onClose={() => setCreating(false)} />
      <ImportModal open={importing} onClose={() => setImporting(false)} />
    </div>
  );
}

const emptyProduct: TrackedProductFormValues = {
  title: "",
  brand: "",
  gtin: "",
  mpn: "",
  external_ref: "",
  image_url: "",
  currency: "USD",
  reference_price: "",
  map_price: "",
};

function CreateProductModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [values, setValues] = useState<TrackedProductFormValues>(emptyProduct);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = <K extends keyof TrackedProductFormValues>(k: K, v: TrackedProductFormValues[K]) =>
    setValues((x) => ({ ...x, [k]: v }));

  const create = useMutation({
    mutationFn: (body: TrackedProductCreate) => api.post<ItemResponse<TrackedProduct>>("/tracked-products", body),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ["tracked-products"] });
      toast.success("Product added", undefined, { href: `/products/${res.data.id}`, label: "Open product" });
      setValues(emptyProduct);
      setErrors({});
      onClose();
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 422) {
        // reference_price.amount -> reference_price
        setErrors(Object.fromEntries(Object.entries(err.fieldErrors).map(([k, v]) => [k.split(".")[0], v])));
      } else toast.error("Could not add product", errorMessage(err));
    },
  });

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    const parsed = trackedProductFormSchema.safeParse(values);
    if (!parsed.success) {
      setErrors(zodErrorToFieldErrors(parsed.error));
      return;
    }
    const v = parsed.data;
    // Prices go out as plain decimals; `currency` applies to both.
    const decimal = (amount: string) => (amount === "" ? null : Number(amount).toFixed(2));
    setErrors({});
    create.mutate({
      title: v.title,
      brand: v.brand || null,
      gtin: v.gtin ? normalizeGtin(v.gtin) : null,
      mpn: v.mpn || null,
      external_ref: v.external_ref || null,
      image_url: v.image_url || null,
      currency: v.currency,
      reference_price: decimal(v.reference_price),
      map_price: decimal(v.map_price),
    });
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Add tracked product"
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" form="create-product-form" variant="primary" loading={create.isPending}>
            Add product
          </Button>
        </>
      }
    >
      <form id="create-product-form" onSubmit={onSubmit} noValidate className="grid gap-4 sm:grid-cols-2">
        <TextField label="Title" value={values.title} onChange={(e) => set("title", e.target.value)} error={errors.title} required containerClassName="sm:col-span-2" />
        <TextField label="Brand" value={values.brand} onChange={(e) => set("brand", e.target.value)} error={errors.brand} />
        <TextField label="External reference" value={values.external_ref} onChange={(e) => set("external_ref", e.target.value)} error={errors.external_ref} hint="Your SKU or catalog ID." />
        <TextField label="GTIN" inputMode="numeric" value={values.gtin} onChange={(e) => set("gtin", e.target.value)} error={errors.gtin} />
        <TextField label="MPN" value={values.mpn} onChange={(e) => set("mpn", e.target.value)} error={errors.mpn} />
        <TextField label="Image URL" type="url" value={values.image_url} onChange={(e) => set("image_url", e.target.value)} error={errors.image_url} containerClassName="sm:col-span-2" />
        <TextField label="Currency" value={values.currency} onChange={(e) => set("currency", e.target.value.toUpperCase())} error={errors.currency} maxLength={3} />
        <div className="hidden sm:block" />
        <TextField label="Reference price" inputMode="decimal" value={values.reference_price} onChange={(e) => set("reference_price", e.target.value)} error={errors.reference_price} placeholder="349.00" />
        <TextField label="MAP price" inputMode="decimal" value={values.map_price} onChange={(e) => set("map_price", e.target.value)} error={errors.map_price} placeholder="299.00" hint="Minimum advertised price." />
      </form>
    </Modal>
  );
}

function ImportModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const inputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<string>();

  const upload = useMutation({
    mutationFn: (f: File) => {
      const fd = new FormData();
      fd.append("file", f);
      return api.post<unknown>("/tracked-products/import", fd);
    },
    onSuccess: () => {
      toast.success("Import queued", "Products will appear as the file is processed.");
      setFile(null);
      if (inputRef.current) inputRef.current.value = "";
      onClose();
      window.setTimeout(() => qc.invalidateQueries({ queryKey: ["tracked-products"] }), 3000);
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 422) setError(err.fieldErrors.file ?? err.message);
      else toast.error("Import failed", errorMessage(err));
    },
  });

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!file) {
      setError("Choose a CSV file");
      return;
    }
    if (!/\.csv$/i.test(file.name) && file.type !== "text/csv") {
      setError("File must be a .csv");
      return;
    }
    setError(undefined);
    upload.mutate(file);
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Import products from CSV"
      description="Columns: title, brand, gtin, mpn, external_ref, image_url, currency, reference_price, map_price."
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button type="submit" form="import-form" variant="primary" loading={upload.isPending}>
            Upload
          </Button>
        </>
      }
    >
      <form id="import-form" onSubmit={onSubmit} noValidate>
        <label htmlFor="import-file" className="block text-sm font-medium">
          CSV file
        </label>
        <input
          ref={inputRef}
          id="import-file"
          type="file"
          accept=".csv,text/csv"
          aria-invalid={Boolean(error) || undefined}
          aria-describedby={error ? "import-file-err" : undefined}
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          className="mt-1 block w-full text-sm file:mr-3 file:rounded-md file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-sm"
        />
        {error && (
          <p id="import-file-err" role="alert" className="mt-1 text-xs text-danger">
            {error}
          </p>
        )}
      </form>
    </Modal>
  );
}
