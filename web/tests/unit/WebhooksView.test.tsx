import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { WebhooksView, describeEvents } from "@/components/settings/WebhooksView";
import type { Webhook, WebhookDelivery } from "@/lib/types";
import { json, mockFetch, renderWithClient } from "../utils";

const endpoint: Webhook = {
  id: "W1",
  url: "https://hooks.example.com/pricelens",
  events: ["*"],
  enabled: true,
  failure_count: 0,
  disabled_at: null,
  created_at: "2026-11-01T09:00:00Z",
};

const delivery: WebhookDelivery = {
  id: "D1",
  event: "ping",
  event_id: "evt_1",
  status: "delivered",
  attempts: 1,
  response_status: 200,
  response_body_excerpt: "ok",
  next_attempt_at: null,
  delivered_at: "2026-11-01T09:01:00Z",
  created_at: "2026-11-01T09:01:00Z",
  payload: {
    id: "evt_1",
    type: "ping",
    created_at: "2026-11-01T09:01:00Z",
    tenant_id: "01TENANT",
    data: { message: "Hello from PriceLens", endpoint_id: "W1" },
  },
};

function setup() {
  return mockFetch((url, init) => {
    const method = init?.method ?? "GET";
    if (method === "GET" && url.startsWith("/api/webhooks?")) return json({ data: [endpoint], meta: { next_cursor: null } });
    if (method === "PATCH" && url === "/api/webhooks/W1") return json({ data: { ...endpoint, secret: "whsec_rotated123" } });
    if (method === "GET" && url.startsWith("/api/webhooks/W1/deliveries")) return json({ data: [delivery], meta: { next_cursor: null } });
    return undefined;
  });
}

beforeEach(() => {
  vi.spyOn(window, "confirm").mockReturnValue(true);
});

describe("WebhooksView", () => {
  it("describes wildcard subscriptions", async () => {
    expect(describeEvents(["*"])).toBe("All events (*)");
    expect(describeEvents(["ping", "search.completed"])).toBe("ping, search.completed");
    setup();
    renderWithClient(<WebhooksView />);
    expect(await screen.findByText("All events (*)")).toBeInTheDocument();
  });

  it("rotates the secret and shows the new one once", async () => {
    const user = userEvent.setup();
    const fetch = setup();
    renderWithClient(<WebhooksView />);
    await user.click(await screen.findByRole("button", { name: "Rotate secret" }));

    await waitFor(() => expect(fetch.bodies("PATCH", "/api/webhooks/W1")).toEqual([{ rotate_secret: true }]));
    expect(await screen.findByDisplayValue("whsec_rotated123")).toBeInTheDocument();
    expect(screen.getByText(/shown only once/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Done" }));
    await waitFor(() => expect(screen.queryByDisplayValue("whsec_rotated123")).toBeNull());
  });

  it("does not rotate when the confirmation is dismissed", async () => {
    vi.spyOn(window, "confirm").mockReturnValue(false);
    const user = userEvent.setup();
    const fetch = setup();
    renderWithClient(<WebhooksView />);
    await user.click(await screen.findByRole("button", { name: "Rotate secret" }));
    expect(fetch.bodies("PATCH", "/api/webhooks/W1")).toEqual([]);
  });

  it("requests payloads and expands them in the delivery log", async () => {
    const user = userEvent.setup();
    const fetch = setup();
    renderWithClient(<WebhooksView />);
    await user.click(await screen.findByRole("button", { name: "Deliveries" }));

    const toggle = await screen.findByRole("button", { name: "Show payload" });
    expect(fetch.calls().some(([url]) => url.startsWith("/api/webhooks/W1/deliveries?") && url.includes("include_payload=1"))).toBe(true);
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByLabelText("Payload for ping")).toBeNull();

    await user.click(toggle);
    const hide = screen.getByRole("button", { name: "Hide payload" });
    expect(hide).toHaveAttribute("aria-expanded", "true");
    const payload = screen.getByLabelText("Payload for ping");
    expect(hide).toHaveAttribute("aria-controls", payload.closest("tr")!.id);
    expect(payload).toHaveTextContent('"message": "Hello from PriceLens"');

    await user.click(hide);
    expect(screen.queryByLabelText("Payload for ping")).toBeNull();
  });

  it("offers ping and an all-events option when creating an endpoint", async () => {
    const user = userEvent.setup();
    setup();
    renderWithClient(<WebhooksView />);
    await user.click(await screen.findByRole("button", { name: "Add endpoint" }));
    const dialog = screen.getByRole("dialog", { name: "Add webhook endpoint" });
    expect(within(dialog).getByRole("checkbox", { name: "ping" })).toBeInTheDocument();
    await user.click(within(dialog).getByRole("checkbox", { name: "All events" }));
    expect(within(dialog).getByRole("checkbox", { name: "ping" })).toBeDisabled();
    expect(within(dialog).getByRole("checkbox", { name: "ping" })).toBeChecked();
  });
});
