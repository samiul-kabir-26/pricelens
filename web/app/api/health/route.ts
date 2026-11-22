/** Liveness probe for the web container (does not call the Laravel API). */
export function GET(): Response {
  return Response.json({ status: "ok" }, { headers: { "Cache-Control": "no-store" } });
}
