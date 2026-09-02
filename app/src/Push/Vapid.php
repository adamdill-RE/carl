<?php

declare(strict_types=1);

namespace Carl\Push;

use Carl\Core\Database;

/**
 * VAPID (RFC 8292): the key pair that identifies THIS Carl to the push
 * services, and the signed token that goes with every push.
 *
 * Everything here is the core openssl extension, which the host has
 * (hosting Section 4): a P-256 key, an ES256 signature, and the DER-to-raw
 * conversion a JWT wants. No library, for the same reason the QR encoder
 * and the SMTP client were written by hand -- nothing installs packages on
 * this host (hosting Section 3).
 *
 * THE PAIR LIVES IN THE DATABASE, generated once by ensure(). There is no
 * shell to run `openssl ecparam` in and no safe way to paste a private key
 * into config/local.php by hand, so /setup makes one the first time it runs
 * after migration 027. Rotating it would invalidate every subscription, so
 * ensure() never overwrites a row.
 */
final class Vapid
{
    private const CURVE = 'prime256v1';

    /**
     * The pair, generating it on first use.
     *
     * @return array{public:string,private:string} base64url: the 65-byte
     *         uncompressed point, and the 32-byte scalar
     */
    public static function ensure(Database $db): array
    {
        $row = $db->one('SELECT `public_key`, `private_key` FROM `push_key` WHERE `id` = 1');
        if ($row !== null) {
            return ['public' => (string) $row['public_key'], 'private' => (string) $row['private_key']];
        }

        $pair = self::generate();
        // Two setups racing produce one row; the loser reads the winner's.
        $db->run(
            'INSERT IGNORE INTO `push_key` (`id`, `public_key`, `private_key`, `created_at`)'
            . ' VALUES (1, :public, :private, UTC_TIMESTAMP())',
            ['public' => $pair['public'], 'private' => $pair['private']]
        );
        $row = $db->one('SELECT `public_key`, `private_key` FROM `push_key` WHERE `id` = 1');
        return ['public' => (string) ($row['public_key'] ?? $pair['public']),
                'private' => (string) ($row['private_key'] ?? $pair['private'])];
    }

    /** The pair if one exists, without making one: for pages that only read. */
    public static function existing(Database $db): ?array
    {
        $row = $db->one('SELECT `public_key`, `private_key` FROM `push_key` WHERE `id` = 1');
        return $row === null ? null
            : ['public' => (string) $row['public_key'], 'private' => (string) $row['private_key']];
    }

    /** @return array{public:string,private:string} */
    public static function generate(): array
    {
        $key = \openssl_pkey_new(['curve_name' => self::CURVE, 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('openssl could not make a P-256 key: ' . (string) \openssl_error_string());
        }
        $details = \openssl_pkey_get_details($key);
        if ($details === false || !isset($details['ec'])) {
            throw new \RuntimeException('openssl gave no EC details for the new key.');
        }
        return [
            'public'  => self::b64url(self::point($details['ec']['x'], $details['ec']['y'])),
            'private' => self::b64url(self::pad($details['ec']['d'])),
        ];
    }

    /**
     * The Authorization header for one push to one endpoint.
     *
     * The token's audience is the push service's origin, its subject is who
     * to write to when a push misbehaves -- Apple requires a mailto: or an
     * https URL there, or answers 403 -- and it expires within twelve hours,
     * inside the specification's twenty-four.
     *
     * @param array{public:string,private:string} $pair
     */
    public static function authorization(string $endpoint, string $subject, array $pair, ?int $now = null): string
    {
        $now ??= \time();
        $origin = self::origin($endpoint);

        $header = self::b64url('{"typ":"JWT","alg":"ES256"}');
        $claims = self::b64url((string) \json_encode([
            'aud' => $origin,
            'exp' => $now + 12 * 3600,
            'sub' => $subject,
        ], \JSON_UNESCAPED_SLASHES));

        $signature = self::sign($header . '.' . $claims, $pair);

        return 'vapid t=' . $header . '.' . $claims . '.' . self::b64url($signature)
            . ', k=' . $pair['public'];
    }

    /** ES256 over the signing input: a raw r||s of 64 bytes, as a JWT wants. */
    public static function sign(string $input, array $pair): string
    {
        $key = self::privateKey($pair);
        $der = '';
        if (!\openssl_sign($input, $der, $key, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('openssl_sign failed: ' . (string) \openssl_error_string());
        }
        return self::derToRaw($der);
    }

    /** Check a raw signature against the pair's public key; for the suite. */
    public static function verify(string $input, string $rawSignature, string $publicB64url): bool
    {
        $point = self::b64urlDecode($publicB64url);
        $key = self::publicKeyFromPoint($point);
        return \openssl_verify($input, self::rawToDer($rawSignature), $key, \OPENSSL_ALGO_SHA256) === 1;
    }

    /** @return string the scheme and host of a push endpoint */
    public static function origin(string $endpoint): string
    {
        $parts = \parse_url($endpoint);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Not a push endpoint: ' . $endpoint);
        }
        return \strtolower((string) $parts['scheme']) . '://' . \strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    // -- Keys as openssl wants them ------------------------------------------

    /** @param array{public:string,private:string} $pair */
    public static function privateKey(array $pair): \OpenSSLAsymmetricKey
    {
        $point = self::b64urlDecode($pair['public']);
        $d = self::b64urlDecode($pair['private']);
        if (\strlen($point) !== 65 || $point[0] !== "\x04" || \strlen($d) !== 32) {
            throw new \InvalidArgumentException('Not a P-256 key pair.');
        }
        $key = \openssl_pkey_new(['ec' => [
            'curve_name' => self::CURVE,
            'x' => \substr($point, 1, 32),
            'y' => \substr($point, 33, 32),
            'd' => $d,
        ]]);
        if ($key === false) {
            throw new \RuntimeException('openssl refused the private key: ' . (string) \openssl_error_string());
        }
        return $key;
    }

    /** A public key from a 65-byte uncompressed point. */
    public static function publicKeyFromPoint(string $point): \OpenSSLAsymmetricKey
    {
        if (\strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new \InvalidArgumentException('Not an uncompressed P-256 point.');
        }
        $key = \openssl_pkey_new(['ec' => [
            'curve_name' => self::CURVE,
            'x' => \substr($point, 1, 32),
            'y' => \substr($point, 33, 32),
        ]]);
        if ($key === false) {
            throw new \RuntimeException('openssl refused the public key: ' . (string) \openssl_error_string());
        }
        return $key;
    }

    /** The 65-byte uncompressed point of an openssl EC key. */
    public static function pointOf(\OpenSSLAsymmetricKey $key): string
    {
        $details = \openssl_pkey_get_details($key);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('Not an EC key.');
        }
        return self::point($details['ec']['x'], $details['ec']['y']);
    }

    private static function point(string $x, string $y): string
    {
        return "\x04" . self::pad($x) . self::pad($y);
    }

    /** openssl drops leading zero bytes from a coordinate; a point wants 32. */
    private static function pad(string $bytes): string
    {
        return \str_pad(\substr($bytes, -32), 32, "\x00", \STR_PAD_LEFT);
    }

    // -- DER <-> raw --------------------------------------------------------

    /** openssl signs as a DER SEQUENCE of two INTEGERs; a JWT wants r||s. */
    public static function derToRaw(string $der): string
    {
        $offset = 2;
        if (\ord($der[1]) & 0x80) {
            $offset += \ord($der[1]) & 0x7f;
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if ($der[$offset] !== "\x02") {
                throw new \RuntimeException('Malformed DER signature.');
            }
            $length = \ord($der[$offset + 1]);
            $value = \substr($der, $offset + 2, $length);
            $offset += 2 + $length;
            $parts[] = \str_pad(\ltrim($value, "\x00"), 32, "\x00", \STR_PAD_LEFT);
        }
        return $parts[0] . $parts[1];
    }

    public static function rawToDer(string $raw): string
    {
        if (\strlen($raw) !== 64) {
            throw new \InvalidArgumentException('A raw ES256 signature is 64 bytes.');
        }
        $integer = static function (string $value): string {
            $value = \ltrim($value, "\x00");
            if ($value === '' || (\ord($value[0]) & 0x80)) {
                $value = "\x00" . $value;
            }
            return "\x02" . \chr(\strlen($value)) . $value;
        };
        $body = $integer(\substr($raw, 0, 32)) . $integer(\substr($raw, 32));
        return "\x30" . \chr(\strlen($body)) . $body;
    }

    // -- base64url ----------------------------------------------------------

    public static function b64url(string $bytes): string
    {
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $text): string
    {
        $decoded = \base64_decode(\strtr($text, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
