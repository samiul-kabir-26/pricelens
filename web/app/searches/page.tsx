import type { Metadata } from "next";
import { SearchHistory } from "@/components/results/SearchHistory";
import { LinkButton } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";

export const metadata: Metadata = { title: "Searches" };

export default function SearchesPage() {
  return (
    <>
      <PageHeader
        title="Searches"
        description="Recent price searches and their status."
        actions={
          <LinkButton href="/" variant="primary">
            New search
          </LinkButton>
        }
      />
      <SearchHistory />
    </>
  );
}
