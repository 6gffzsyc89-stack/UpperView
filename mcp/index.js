#!/usr/bin/env node
/**
 * Upper View Samui management MCP (stdio).
 * Talks to the website backend (which fronts Beds24 + the local fallback
 * engine), so credentials stay on the server. Admin key comes from the
 * UV_ADMIN_KEY env var or ~/.config/upperview/admin_key.
 */
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { readFileSync } from "node:fs";
import { homedir } from "node:os";
import { join } from "node:path";

const BASE = process.env.UV_BASE_URL || "https://upperviewsamui.com/booking/api.php";
function adminKey() {
  if (process.env.UV_ADMIN_KEY) return process.env.UV_ADMIN_KEY.trim();
  try {
    return readFileSync(join(homedir(), ".config", "upperview", "admin_key"), "utf8").trim();
  } catch {
    throw new Error("Admin key not found: set UV_ADMIN_KEY or ~/.config/upperview/admin_key");
  }
}

async function call(action, { method = "GET", params = {}, body } = {}) {
  const url = new URL(BASE);
  url.searchParams.set("action", action);
  url.searchParams.set("k", adminKey());
  for (const [k, v] of Object.entries(params)) if (v != null) url.searchParams.set(k, String(v));
  const res = await fetch(url, {
    method,
    headers: body ? { "Content-Type": "application/json" } : undefined,
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 400)}`);
  return text;
}

const asResult = (text) => ({ content: [{ type: "text", text }] });
const DATE = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "YYYY-MM-DD");
const TYPE = z.enum(["villa", "studio"]);

const server = new McpServer({ name: "upperview", version: "1.0.0" });

server.tool(
  "get_availability",
  "Free units per date for a room type (villa: 8 Mini Villas, studio: 3 Studios). Dates are YYYY-MM-DD; 'to' is exclusive.",
  { type: TYPE, from: DATE, to: DATE },
  async ({ type, from, to }) =>
    asResult(await call("availability", { params: { type, from, to } }))
);

server.tool(
  "get_occupancy",
  "Occupancy percentage and booked-night counts per room type for a date range (defaults: next 30 days).",
  { from: DATE.optional(), to: DATE.optional() },
  async ({ from, to }) => asResult(await call("admin-occupancy", { params: { from, to } }))
);

server.tool(
  "list_bookings",
  "All upcoming bookings and blocks: the website database plus (when connected) the live Beds24 booking list including OTA bookings.",
  {},
  async () => asResult(await call("admin-bookings"))
);

server.tool(
  "block_dates",
  "Block a unit of a room type for a date range (maintenance, phone booking, etc.). Creates the block on the website and in Beds24.",
  { type: TYPE, checkin: DATE, checkout: DATE, note: z.string().optional() },
  async ({ type, checkin, checkout, note }) =>
    asResult(await call("admin-block", { method: "POST", body: { type, checkin, checkout, note } }))
);

server.tool(
  "set_nightly_rate",
  "Set the nightly price (THB) for a room type over a date range in Beds24. Experimental: reports Beds24's raw response.",
  { type: TYPE, from: DATE, to: DATE, price: z.number().positive() },
  async ({ type, from, to, price }) =>
    asResult(await call("admin-rate", { method: "POST", body: { type, from, to, price } }))
);

server.tool(
  "beds24_status",
  "Health of the Beds24 connection (enabled, token stored, last API error). The site falls back to its own iCal engine when Beds24 fails.",
  {},
  async () => asResult(await call("admin-beds24-status"))
);

await server.connect(new StdioServerTransport());
