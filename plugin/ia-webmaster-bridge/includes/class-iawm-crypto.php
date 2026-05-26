<?php
/**
 * Symmetric encryption-at-rest helper.
 *
 * Phase 10.4 introduced this helper to encrypt the webhook
 * `signing_secret` column before it lands in `wp_iawm_webhooks`. The
 * threat model is a database-only leak (e.g. an unattended SQL dump,
 * a misconfigured backup share) where the attacker does NOT also have
 * access to the WordPress filesystem: in that case `wp-config.php` —
 * and the `AUTH_KEY` it carries — stays out of reach, and the
 * ciphertext is meaningless without the derived key.
 *
 * Scheme:
 *   - **Cipher**: AES-256-CBC with `OPENSSL_RAW_DATA`.
 *   - **IV**: 16 bytes from `openssl_random_pseudo_bytes()`. Stored
 *     in the envelope alongside the ciphertext so each value has its
 *     own IV (no IV reuse across rows).
 *   - **Key**: 32 raw bytes derived as
 *     `sha256( AUTH_KEY . '|iawm-webhook-secret' )`. Reusing
 *     `AUTH_KEY` keeps the operator's key-management surface to a
 *     single place: it is already the secret that protects passwords
 *     and auth cookies, the most sensitive data WordPress itself
 *     stores. The salt suffix scopes the key to this purpose so a
 *     future feature can derive a sibling key without colliding.
 *
 * Envelope:
 *   `iawm-enc:v1:<base64(iv)>:<base64(ciphertext)>`
 *
 * The `v1` token is a hard version marker: the decryptor sniffs the
 * prefix so a future v2 scheme can coexist with legacy v1 rows
 * without requiring a one-shot migration. Values that do NOT carry
 * the prefix are returned as-is, which is how the original v1.3.x
 * plaintext rows continue to read transparently after upgrade until
 * the one-time migration in `IAWM_Webhook` rewrites them.
 *
 * Why CBC and not GCM:
 *   GCM provides built-in authenticated encryption, which is
 *   stronger in theory. We picked CBC because (a) we do not need
 *   tampering detection here — the secret is opaque and any
 *   corrupted decrypt simply produces a bad HMAC, which the
 *   receiver rejects exactly like a stale secret; (b) authenticated
 *   modes add tag-handling complexity (tag length, tag-as-AAD
 *   surface) that we would otherwise have to specify and test; (c)
 *   the threat model is confidentiality-at-rest only — integrity of
 *   the secret is moot if an attacker can already write to the DB.
 *
 * Not used for: API signing, where `hash_hmac` already gives us
 * authenticated MACs. This helper is encrypt-only and does NOT need
 * constant-time comparison.
 *
 * See D-032 for the full rationale.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypt / decrypt short secrets at rest, with a versioned envelope.
 */
class IAWM_Crypto {

	/**
	 * Versioned envelope prefix. New schemes bump to v2: ... so the
	 * decryptor can branch on the version token.
	 */
	const PREFIX = 'iawm-enc:v1:';

	/**
	 * OpenSSL cipher identifier. AES-256-CBC requires a 32-byte key
	 * and a 16-byte IV.
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * IV length for AES-CBC.
	 */
	const IV_LENGTH = 16;

	/**
	 * Encrypt a string with AES-256-CBC and wrap it in the versioned
	 * envelope.
	 *
	 * Empty strings encrypt to empty strings (no envelope) so a NULL /
	 * absent column stays representable end-to-end without confusing
	 * the decryptor's "does this look encrypted?" sniff. Real secrets
	 * are validated upstream to be at least 16 characters, so we do
	 * not lose information by short-circuiting on empties here.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Versioned envelope, or '' if the input was empty.
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		// Already encrypted — idempotent so callers can safely run a
		// migration loop over a mixed-state column.
		if ( 0 === strpos( $plaintext, self::PREFIX ) ) {
			return $plaintext;
		}

		$iv  = openssl_random_pseudo_bytes( self::IV_LENGTH );
		$key = self::derive_key();

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);
		if ( false === $ciphertext ) {
			// In the unlikely event OpenSSL fails (bad cipher, no
			// entropy), do NOT return the plaintext — that would
			// silently downgrade encryption to none. Return an empty
			// string and let the caller observe the column went blank.
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'OpenSSL encryption failed for IAWM_Crypto::encrypt.', 'ia-webmaster-bridge' ),
				'1.4.0'
			);
			return '';
		}

		return self::PREFIX
			. base64_encode( $iv )
			. ':'
			. base64_encode( $ciphertext );
	}

	/**
	 * Decrypt a value. If it does not carry the versioned envelope
	 * prefix, it is returned as-is — that is the legacy-plaintext
	 * fallback that keeps pre-encryption rows readable until the
	 * one-time migration rewrites them.
	 *
	 * Decrypt failures (malformed envelope, wrong key, wrong IV) fall
	 * back to the empty string. They cannot legitimately occur on a
	 * healthy install — if they do, the operator should rotate the
	 * affected secret rather than try to recover.
	 *
	 * @param string $value Stored value (envelope or legacy plaintext).
	 * @return string Plaintext value.
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			// Legacy plaintext — return untouched.
			return $value;
		}

		$body  = substr( $value, strlen( self::PREFIX ) );
		$parts = explode( ':', $body, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}

		$iv         = base64_decode( $parts[0], true );
		$ciphertext = base64_decode( $parts[1], true );
		if ( false === $iv || false === $ciphertext || self::IV_LENGTH !== strlen( $iv ) ) {
			return '';
		}

		$key       = self::derive_key();
		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);
		if ( false === $plaintext ) {
			return '';
		}
		return $plaintext;
	}

	/**
	 * Quick sniff to tell whether a stored value is already
	 * encrypted with the current scheme. Used by the migration loop
	 * to skip rows that are already encrypted.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Derive the 32-byte AES key from `AUTH_KEY` + a fixed salt.
	 *
	 * `AUTH_KEY` is read at call time (not cached as a class constant)
	 * so test harnesses can override the constant per-process and so
	 * `wp-config.php` rotations do not require a plugin reload to
	 * take effect on subsequent calls. The salt suffix scopes the
	 * derived key to webhook secrets — a future caller deriving a
	 * sibling key for another purpose should use a different salt
	 * suffix so the two keys cannot be confused.
	 *
	 * If `AUTH_KEY` is undefined (which should never happen on a real
	 * WordPress install — `wp-config.php` defines it during the WP
	 * install wizard) we fall back to a sentinel constant
	 * `IAWM_FALLBACK_KEY`, log a `_doing_it_wrong` notice, and
	 * effectively still derive a key. The fallback exists to keep
	 * test environments and CI pipelines that bypass the full WP
	 * bootstrap working; it must not be relied upon in production
	 * (the resulting key is constant across all such installs).
	 *
	 * @return string Raw 32-byte AES-256 key.
	 */
	private static function derive_key() {
		if ( defined( 'AUTH_KEY' ) && '' !== (string) AUTH_KEY ) {
			$base = (string) AUTH_KEY;
		} elseif ( defined( 'IAWM_FALLBACK_KEY' ) && '' !== (string) IAWM_FALLBACK_KEY ) {
			$base = (string) IAWM_FALLBACK_KEY;
		} else {
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'AUTH_KEY is not defined; falling back to a sentinel key. Define AUTH_KEY in wp-config.php.', 'ia-webmaster-bridge' ),
				'1.4.0'
			);
			$base = 'iawm-fallback-key-placeholder';
		}

		return hash( 'sha256', $base . '|iawm-webhook-secret', true );
	}
}
