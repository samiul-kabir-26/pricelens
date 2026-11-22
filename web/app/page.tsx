import type { Metadata } from "next";
import { SearchForm } from "@/components/search/SearchForm";
import { PageHeader } from "@/components/ui/PageHeader";

export const metadata: Metadata = { title: "New search" };

export default function HomePage() {
  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        title="Find the best price"
        description="Search across your configured retailers, shopping engines and the open web. Results stream in as each source responds."
      />
      <SearchForm />
    </div>
  );
}
