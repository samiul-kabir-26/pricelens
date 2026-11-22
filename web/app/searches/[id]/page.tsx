import type { Metadata } from "next";
import { Suspense } from "react";
import { SearchView } from "@/components/results/SearchView";
import { PageSkeleton } from "@/components/ui/Skeleton";

export const metadata: Metadata = { title: "Search results" };

async function SearchLoader({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <SearchView id={id} />;
}

export default function SearchPage({ params }: PageProps<"/searches/[id]">) {
  return (
    <Suspense fallback={<PageSkeleton />}>
      <SearchLoader params={params} />
    </Suspense>
  );
}
