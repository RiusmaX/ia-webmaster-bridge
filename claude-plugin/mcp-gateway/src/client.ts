/**
 * Signed HTTP client for the IA Webmaster Bridge adapter API.
 *
 * Each request is signed with HMAC-SHA256 following the scheme expected by the
 * plugin (see includes/class-iawm-auth.php, build_message method).
 */

import { createHash, createHmac, randomBytes } from "node:crypto";
import type { GatewayConfig } from "./config.js";

/** REST namespace exposed by the plugin. */
const NAMESPACE = "ia-webmaster/v1";

/** Signature scheme prefix (must match the plugin). */
const SIGNATURE_SCHEME = "IAWM-HMAC-SHA256";

export interface ApiResult {
  /** True if the HTTP status code is 2xx. */
  ok: boolean;
  /** HTTP status code of the response. */
  status: number;
  /** Response body (decoded JSON object, or raw text as a fallback). */
  data: unknown;
}

/**
 * Minimal client for the adapter API.
 */
export class IawmClient {
  constructor(private readonly config: GatewayConfig) {}

  /**
   * Calls a GET route on the adapter.
   *
   * @param path Path relative to the namespace, e.g. "/status".
   */
  async get(path: string): Promise<ApiResult> {
    return this.request("GET", path);
  }

  /**
   * Calls a POST route on the adapter with a JSON body.
   *
   * @param path    Path relative to the namespace, e.g. "/content/list".
   * @param payload Object serialized to JSON and signed.
   */
  async post(path: string, payload: unknown): Promise<ApiResult> {
    return this.request("POST", path, payload);
  }

  private async request(
    method: string,
    path: string,
    payload?: unknown,
  ): Promise<ApiResult> {
    const route = `/${NAMESPACE}${path}`;
    const url = `${this.config.baseUrl}/wp-json${route}`;
    const body = payload === undefined ? "" : JSON.stringify(payload);
    const timestamp = Math.floor(Date.now() / 1000).toString();
    const nonce = randomBytes(16).toString("hex");

    // Canonical message — seven lines, identical to the plugin-side construction.
    const message = [
      SIGNATURE_SCHEME,
      method.toUpperCase(),
      route,
      "", // empty canonical query: all parameters go through the body
      timestamp,
      nonce,
      createHash("sha256").update(body).digest("hex"),
    ].join("\n");

    const signature = createHmac("sha256", this.config.secret)
      .update(message)
      .digest("hex");

    const headers: Record<string, string> = {
      "X-IAWM-Key": this.config.keyId,
      "X-IAWM-Timestamp": timestamp,
      "X-IAWM-Nonce": nonce,
      "X-IAWM-Signature": signature,
      Accept: "application/json",
    };
    if (body !== "") {
      headers["Content-Type"] = "application/json";
    }

    const init: { method: string; headers: Record<string, string>; body?: string } = {
      method,
      headers,
    };
    if (body !== "") {
      init.body = body;
    }

    let response: Response;
    try {
      response = await fetch(url, init);
    } catch (err) {
      throw new Error(
        `Failed to connect to ${url}: ${(err as Error).message}`,
      );
    }

    const text = await response.text();
    let data: unknown = text;
    try {
      data = JSON.parse(text);
    } catch {
      // Non-JSON response: keep the raw text.
    }

    return { ok: response.ok, status: response.status, data };
  }
}
