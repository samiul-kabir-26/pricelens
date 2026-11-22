"use client";

import { Button } from "@/components/ui/Button";

export default function GlobalError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <div role="alert" className="rounded-lg border border-danger/30 bg-danger-soft p-6 text-sm text-danger">
      <p className="font-medium">Something went wrong</p>
      <p className="mt-1">{error.message}</p>
      <Button className="mt-4" onClick={reset}>
        Try again
      </Button>
    </div>
  );
}
