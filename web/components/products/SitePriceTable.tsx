import { Badge, type Tone } from "@/components/ui/Badge";
import { Card, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { ExternalLink } from "@/components/ui/ExternalLink";
import { TBody, THead, Table, Td, Th } from "@/components/ui/Table";
import { formatDateTime, humanize } from "@/lib/format";
import { compareMoney, formatMoney, moneyValue } from "@/lib/money";
import type { LatestPrice, Money } from "@/lib/types";

const AVAIL_TONE: Record<string, Tone> = {
  in_stock: "success",
  limited: "warning",
  preorder: "info",
  backorder: "warning",
  out_of_stock: "danger",
  discontinued: "danger",
};

interface Props {
  prices: LatestPrice[];
  mapPrice?: Money | null;
}

export function SitePriceTable({ prices, mapPrice }: Props) {
  const sorted = [...prices].sort((a, b) =>
    compareMoney(a.converted_total ?? a.total ?? a.price, b.converted_total ?? b.total ?? b.price),
  );
  const map = moneyValue(mapPrice);

  return (
    <Card>
      <CardHeader title="Latest prices by site" description="Most recent snapshot per site, cheapest first." />
      {sorted.length === 0 ? (
        <div className="p-4">
          <EmptyState title="No prices yet" description="Prices appear after the first monitor run." />
        </div>
      ) : (
        <Table caption="Latest prices by site">
          <THead>
            <tr>
              <Th>Site</Th>
              <Th className="text-right">Price</Th>
              <Th className="text-right">Total</Th>
              <Th className="text-right">Converted</Th>
              <Th>Availability</Th>
              <Th>Observed</Th>
            </tr>
          </THead>
          <TBody>
            {sorted.map((p) => {
              const price = moneyValue(p.price);
              const belowMap = map !== null && price !== null && price < map && p.price?.currency === mapPrice?.currency;
              const availability = p.availability ?? "unknown";
              return (
                <tr key={`${p.site_domain}-${p.seller ?? ""}-${p.url ?? ""}`}>
                  <Td>
                    {p.url ? (
                      <ExternalLink href={p.url} className="font-medium text-fg hover:underline">
                        {p.site_domain}
                      </ExternalLink>
                    ) : (
                      <span className="font-medium text-fg">{p.site_domain}</span>
                    )}
                    {p.seller && p.seller !== p.site_domain && <span className="block text-xs text-fg-muted">Sold by {p.seller}</span>}
                  </Td>
                  <Td className="text-right tabular-nums">
                    {formatMoney(p.price)}
                    {belowMap && (
                      <Badge tone="danger" className="ml-2">
                        Below MAP
                      </Badge>
                    )}
                  </Td>
                  <Td className="text-right tabular-nums">{formatMoney(p.total)}</Td>
                  <Td className="text-right tabular-nums text-fg-muted">
                    {p.converted_total && p.converted_total.currency !== (p.total ?? p.price)?.currency ? formatMoney(p.converted_total) : "—"}
                  </Td>
                  <Td>
                    <Badge tone={AVAIL_TONE[availability] ?? "neutral"}>{humanize(availability)}</Badge>
                  </Td>
                  <Td className="whitespace-nowrap text-fg-muted">{formatDateTime(p.observed_at)}</Td>
                </tr>
              );
            })}
          </TBody>
        </Table>
      )}
    </Card>
  );
}
