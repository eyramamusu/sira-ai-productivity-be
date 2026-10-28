<?php

namespace App\Billing;

use RuntimeException;

/** Minimal JWT signing for the store APIs (RS256 for Google, ES256 for Apple). */
final class Jwt
{
    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    public static function sign(array $header, array $payload, string $privateKeyPem, string $alg): string
    {
        $input = self::b64(json_encode($header + ['alg' => $alg, 'typ' => 'JWT'])).'.'.self::b64(json_encode($payload));
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false || ! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the store API token; check the private key.');
        }

        return $input.'.'.self::b64($alg === 'ES256' ? self::derToRaw($signature, 32) : $signature);
    }

    /** Payload of a JWS without checking its signature (only for data fetched from the store over TLS). */
    public static function payload(string $jws): array
    {
        $parts = explode('.', $jws);

        return count($parts) === 3 ? (json_decode(self::unb64($parts[1]), true) ?: []) : [];
    }

    /** OpenSSL returns ECDSA signatures as DER; JWT wants the raw r||s concatenation. */
    private static function derToRaw(string $der, int $size): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $parts = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $parts .= str_pad(ltrim($int, "\x00"), $size, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $parts;
    }
}
