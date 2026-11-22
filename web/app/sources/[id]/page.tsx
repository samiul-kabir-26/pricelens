import type { Metadata } from "next";
import { Suspense } from "react";
import { SourceDetail } from "@/components/sources/SourceDetail";
import { PageSkeleton } from "@/components/ui/Skeleton";

export const metadata: Metadata = { title: "Source adapter" };

async function Loader({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <SourceDetail id={id} />;
}

export default function SourcePage({ params }: PageProps<"/sources/[id]">) {
  return (
    <Suspense fallback={<PageSkeleton />}>
      <Loader params={params} />
    </Suspense>
  );
}
