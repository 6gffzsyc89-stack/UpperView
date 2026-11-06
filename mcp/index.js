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
const TYPE = z.enum(["villa", "superior", "deluxe", "king"]);

const server = new McpServer({ name: "upperview", version: "1.1.0" });

server.tool(
  "get_availability",
  "Free units per date for a room type (villa: 8 Mini Villas, superior/deluxe/king: one suite each). Dates are YYYY-MM-DD; 'to' is exclusive.",
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

server.tool(
  "beds24_get",
  "Read ANY Beds24 API v2 GET endpoint (full read mirror of Beds24, property 365322). Key paths: /channels/settings (channel manager: which OTAs are linked per room, channel config), /properties (property + room setup, booking rules; add query includeAllRooms=true), /bookings (query filters like arrivalFrom=YYYY-MM-DD), /bookings/messages (guest messages; query bookingId=... or filter=unread), /bookings/invoices (invoice + payment lines per booking), /inventory/rooms/calendar (prices/availability; needs roomId, startDate, endDate), /inventory/fixedPrices, /channels/booking/reviews, /accounts. 'query' is a raw query string without the leading '?'.",
  { path: z.string().regex(/^\/[A-Za-z0-9/._-]*$/), query: z.string().optional() },
  async ({ path, query }) =>
    asResult(await call("admin-beds24-get", { params: { path, query } }))
);

server.tool(
  "beds24_write",
  "Write to ANY Beds24 API v2 endpoint (POST/PATCH/DELETE) — full write mirror. 'body' is the JSON payload Beds24 expects (usually an ARRAY of objects). Examples: send a guest a message through their OTA channel: path=/bookings/messages, body=[{bookingId: 95735792, message: 'Hello...'}]; modify a booking: path=/bookings, body=[{id: 95735792, status: 'cancelled'}]; change channel settings: path=/channels/settings. REAL writes that sync to OTAs — only on explicit human instruction, never speculatively.",
  {
    path: z.string().regex(/^\/[A-Za-z0-9/._-]*$/),
    method: z.enum(["POST", "PATCH", "DELETE"]).optional(),
    query: z.string().optional(),
    body: z.any().optional(),
  },
  async ({ path, method, query, body }) =>
    asResult(await call("admin-beds24-write", { method: "POST", body: { path, method, query, body } }))
);

server.tool(
  "send_email",
  "Send an email FROM booking@upperviewsamui.com (the hotel's address) via the website server. Use for replying to guests by email, sending booking info, etc. 'to' is one address or an array. Plain text by default; set html=true if body is HTML. Only send on explicit human/staff instruction.",
  {
    to: z.union([z.string(), z.array(z.string())]),
    subject: z.string().min(1),
    body: z.string().min(1),
    html: z.boolean().optional(),
  },
  async ({ to, subject, body, html }) =>
    asResult(await call("admin-send-email", { method: "POST", body: { to, subject, body, html } }))
);

server.tool(
  "get_notify_emails",
  "The list of addresses that receive ALL booking notification emails (new requests, paid bookings, failures).",
  {},
  async () => asResult(await call("admin-notify-emails"))
);

server.tool(
  "set_notify_emails",
  "Replace the booking-notification email list. Takes the FULL new list (not a delta). Only on explicit human instruction.",
  { emails: z.array(z.string().email()).min(1) },
  async ({ emails }) =>
    asResult(await call("admin-notify-emails", { method: "POST", body: { emails } }))
);

await server.connect(new StdioServerTransport());
