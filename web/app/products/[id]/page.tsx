import type { Metadata } from "next";
import { Suspense } from "react";
import { ProductDetail } from "@/components/products/ProductDetail";
import { PageSkeleton } from "@/components/ui/Skeleton";

export const metadata: Metadata = { title: "Tracked product" };

async function Loader({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <ProductDetail id={id} />;
}

export default function ProductPage({ params }: PageProps<"/products/[id]">) {
  return (
    <Suspense fallback={<PageSkeleton />}>
      <Loader params={params} />
    </Suspense>
  );
}
