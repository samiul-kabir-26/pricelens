import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { AdapterTestPanel } from "@/components/sources/AdapterTestPanel";
import { json, mockFetch, renderWithClient } from "../utils";

const result = {
  http_status: 200,
  timing_ms: 840,
  extraction_method: "json_ld",
  fields: { title: "Sony WH-1000XM5", price: "329.99" },
  field_sources: { title: "json_ld", price: "selector" },
  selector_hits: { price: ["$329.99"], title: "Sony WH-1000XM5", empty: [] },
  candidates: [],
  errors: [],
};

describe("AdapterTestPanel", () => {
  it("sends render: true when 'Render with browser' is checked and shows the result", async () => {
    const user = userEvent.setup();
    const fetch = mockFetch((url, init) => (init?.method === "POST" && url === "/api/sources/S1/test" ? json({ data: result }) : undefined));
    renderWithClient(<AdapterTestPanel sourceId="S1" />);

    const render = screen.getByRole("checkbox", { name: "Render with browser" });
    expect(render).not.toBeChecked();
    await user.click(render);
    await user.type(screen.getByLabelText("Product URL"), "https://shop.example.com/p/1");
    await user.click(screen.getByRole("button", { name: "Run test" }));

    await waitFor(() => expect(fetch.bodies("POST", "/api/sources/S1/test")).toEqual([{ url: "https://shop.example.com/p/1", render: true }]));
    // Extraction method and the title field source both use the method label.
    expect(await screen.findAllByText("JSON-LD")).toHaveLength(2);
    expect(screen.getByText("Selectors")).toBeInTheDocument();
    // Free-form selector hits are normalised to lists.
    expect(screen.getByText("No matches")).toBeInTheDocument();
    expect(screen.getByText("$329.99")).toBeInTheDocument();
  });

  it("sends render: false by default for query tests", async () => {
    const user = userEvent.setup();
    const fetch = mockFetch((url, init) => (init?.method === "POST" ? json({ data: result }) : undefined));
    renderWithClient(<AdapterTestPanel sourceId="S1" />);
    await user.click(screen.getByRole("radio", { name: "Search query" }));
    await user.type(screen.getByLabelText("Search query"), "sony xm5");
    await user.click(screen.getByRole("button", { name: "Run test" }));
    await waitFor(() => expect(fetch.bodies("POST", "/api/sources/S1/test")).toEqual([{ query: "sony xm5", render: false }]));
  });
});
