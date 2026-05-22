/**
 * Client HTTP signé pour l'API de l'adaptateur IA Webmaster Bridge.
 *
 * Chaque requête est signée en HMAC-SHA256 selon le schéma attendu par le
 * plugin (voir includes/class-iawm-auth.php, méthode build_message).
 */

import { createHash, createHmac, randomBytes } from "node:crypto";
import type { GatewayConfig } from "./config.js";

/** Namespace REST exposé par le plugin. */
const NAMESPACE = "ia-webmaster/v1";

/** Préfixe de schéma de signature (doit correspondre au plugin). */
const SIGNATURE_SCHEME = "IAWM-HMAC-SHA256";

export interface ApiResult {
  /** True si le code HTTP est 2xx. */
  ok: boolean;
  /** Code HTTP de la réponse. */
  status: number;
  /** Corps de la réponse (objet JSON décodé, ou texte brut à défaut). */
  data: unknown;
}

/**
 * Client minimal vers l'API de l'adaptateur.
 */
export class IawmClient {
  constructor(private readonly config: GatewayConfig) {}

  /**
   * Appelle une route GET de l'adaptateur.
   *
   * @param path Chemin relatif au namespace, ex. "/status".
   */
  async get(path: string): Promise<ApiResult> {
    return this.request("GET", path);
  }

  /**
   * Appelle une route POST de l'adaptateur avec un corps JSON.
   *
   * @param path    Chemin relatif au namespace, ex. "/content/list".
   * @param payload Objet sérialisé en JSON et signé.
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

    // Message canonique — sept lignes, identique à la construction côté plugin.
    const message = [
      SIGNATURE_SCHEME,
      method.toUpperCase(),
      route,
      "", // query canonique vide : tous les paramètres passent par le corps
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
        `Échec de la connexion à ${url} : ${(err as Error).message}`,
      );
    }

    const text = await response.text();
    let data: unknown = text;
    try {
      data = JSON.parse(text);
    } catch {
      // Réponse non-JSON : on conserve le texte brut.
    }

    return { ok: response.ok, status: response.status, data };
  }
}
