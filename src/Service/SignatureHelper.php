<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

/**
 * Scheme-2 signatures (per-purpose HKDF keys bound to the public store id)
 * and the signed-in customer token.
 *
 * Checked against Emporiqa's own vectors in tests/fixtures/signature_vectors.json,
 * a byte-identical copy of the platform's.
 */
final class SignatureHelper
{
    public const SALT = 'emporiqa-v2';

    /** Sync webhooks, plugin to Emporiqa. */
    public const LABEL_INBOUND = 'plugin-to-emporiqa';

    /** Rule calls, Emporiqa to plugin. */
    public const LABEL_OUTBOUND = 'emporiqa-to-plugin';

    /** Signatures on our answers to rule calls. */
    public const LABEL_RESPONSE = 'response';

    public const MAX_SKEW_SECONDS = 300;

    /**
     * Most v1 values a header may carry. Emporiqa sends two while a store
     * changes its secret (new and old), one otherwise; the cap leaves one
     * spare. More is refused rather than hashed.
     */
    public const MAX_SIGNATURES = 3;

    /**
     * @return string 32 raw bytes
     */
    public static function deriveKey(string $secret, string $label, string $storeId): string
    {
        return hash_hkdf('sha256', $secret, 32, $label . ':' . $storeId, self::SALT);
    }

    /**
     * Header value t=<unix seconds>,v1=<hex HMAC(key, t . "." . message)>.
     */
    public static function buildHeader(string $key, string $message, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $message, $key);
    }

    /**
     * Exactly one t (ASCII digits) and one to MAX_SIGNATURES v1 values (64
     * lowercase hex each); unknown names are ignored. A malformed v1 refuses
     * the whole header.
     *
     * @return array{0: int, 1: string[]}|null
     */
    public static function parseHeader(string $header): ?array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                return null;
            }
            if ($pair[0] === 't') {
                if ($timestamp !== null || !preg_match('/^[0-9]{1,12}$/D', $pair[1])) {
                    return null;
                }
                $timestamp = (int) $pair[1];
            } elseif ($pair[0] === 'v1') {
                if (count($signatures) >= self::MAX_SIGNATURES || !preg_match('/^[0-9a-f]{64}$/D', $pair[1])) {
                    return null;
                }
                $signatures[] = $pair[1];
            }
        }
        if ($timestamp === null || $signatures === []) {
            return null;
        }

        return [$timestamp, $signatures];
    }

    /**
     * Valid when ANY v1 matches: while the store changes its secret Emporiqa
     * signs with both, and this shop holds one.
     *
     * @return string 'ok', 'signature' or 'expired'
     */
    public static function verifyHeader(
        string $header,
        string $body,
        string $secret,
        string $storeId,
        string $label,
        ?int $now = null,
    ): string {
        $parsed = self::parseHeader($header);
        if ($parsed === null || $secret === '' || $storeId === '') {
            return 'signature';
        }
        [$timestamp, $signatures] = $parsed;
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, self::deriveKey($secret, $label, $storeId));
        $matched = false;
        // No early exit, so the time taken does not say which position matched.
        foreach ($signatures as $signature) {
            $matched = hash_equals($expected, $signature) || $matched;
        }
        if (!$matched) {
            return 'signature';
        }
        if (abs(($now ?? time()) - $timestamp) > self::MAX_SKEW_SECONDS) {
            return 'expired';
        }

        return 'ok';
    }

    /**
     * Signed customer token for the chat widget: base64url(payload).hmac_hex
     * with the raw secret. `aud` binds it to this Emporiqa store.
     *
     * Never write it into page HTML or a URL: a full-page cache would serve
     * one customer's token to another, and URLs end up in server logs. The
     * uncached CustomerTokenController serves it instead.
     */
    public static function customerToken(string $customerId, string $secret, string $storeId): string
    {
        $payload = json_encode(
            ['uid' => $customerId, 'ts' => time(), 'aud' => $storeId],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return $encoded . '.' . hash_hmac('sha256', $encoded, $secret);
    }
}
