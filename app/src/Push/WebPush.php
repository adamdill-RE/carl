<?php

declare(strict_types=1);

namespace Carl\Push;

use Carl\Core\HttpClient;

/**
 * Web Push, written directly against RFC 8291 (message encryption), RFC 8188
 * (the aes128gcm content encoding) and RFC 8292 (VAPID), with the core
 * openssl extension: ECDH through openssl_pkey_derive, HKDF through
 * hash_hkdf, AES-128-GCM through openssl_encrypt. About the size the Phase
 * 15 handoff estimated, and tested the way the SMTP client is -- against the
 * published arithmetic of RFC 8291 Appendix A, not a mock (29_timers_test).
 *
 * THE PAYLOAD IS DECLARATIVE. The JSON this sends carries `web_push: 8030`
 * and a `notification` object, which Safari 18.4+ and iOS 18.4+ show with no
 * service worker at all, and which sw.js shows for every other browser from
 * its push handler. One body, both worlds.
 *
 * THE TRANSPORT IS INJECTABLE. Sending is one HTTPS POST to whatever push
 * service the browser named -- Apple's, Google's, Mozilla's -- and the suite
 * swaps in a function that records the request instead, so the encryption
 * and the headers are asserted against a receiver that decrypts them with
 * RFC 8291's own receiver key.
 */
final class WebPush
{
    /** RFC 8188: one record, larger than any payload this sends. */
    private const RECORD_SIZE = 4096;

    /** @var callable(string,string,list<string>):array{status:int,error:?string} */
    private $transport;

    /**
     * @param array{public:string,private:string} $vapid the install's pair
     * @param string $subject who to write to about a misbehaving push:
     *        a mailto: or an https URL (Apple refuses anything else)
     * @param callable|null $transport (url, body, headers) => {status, error};
     *        null is curl through HttpClient
     */
    public function __construct(
        private array $vapid,
        private string $subject,
        private int $ttlSeconds = 3600,
        ?callable $transport = null,
        ?HttpClient $http = null,
    ) {
        $http ??= new HttpClient('CarlTheGardenHelper/1.0 (web push)', 15);
        $this->transport = $transport ?? static function (string $url, string $body, array $headers) use ($http): array {
            $result = $http->postRaw($url, $body, $headers);
            return ['status' => $result->status, 'error' => $result->error];
        };
    }

    /**
     * Send one message to one subscription.
     *
     * @param array{endpoint:string,p256dh:string,auth:string} $subscription
     * @return array{ok:bool,status:int,gone:bool,error:?string}
     */
    public function send(array $subscription, string $payload, string $topic = ''): array
    {
        try {
            $body = self::encrypt($payload, (string) $subscription['p256dh'], (string) $subscription['auth'])['body'];
            $authorization = Vapid::authorization((string) $subscription['endpoint'], $this->subject, $this->vapid);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => $e->getMessage()];
        }

        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . \strlen($body),
            'TTL: ' . $this->ttlSeconds,
            'Urgency: high',
            'Authorization: ' . $authorization,
        ];
        if ($topic !== '') {
            // A later push on the same topic replaces an undelivered earlier
            // one, so a phone that was off for an hour gets one, not six.
            $headers[] = 'Topic: ' . \substr(\preg_replace('/[^A-Za-z0-9_-]/', '-', $topic) ?? '', 0, 32);
        }

        $result = ($this->transport)((string) $subscription['endpoint'], $body, $headers);
        $status = (int) $result['status'];
        // 404 and 410 both mean the subscription no longer exists; the caller
        // marks it so the next timer does not try it again.
        $gone = $status === 404 || $status === 410;
        $ok = $status >= 200 && $status < 300;

        return [
            'ok'     => $ok,
            'status' => $status,
            'gone'   => $gone,
            'error'  => $ok ? null : ($result['error'] ?? ('HTTP ' . $status)),
        ];
    }

    /**
     * RFC 8291 Section 3, exactly as its Appendix A walks it.
     *
     * The application-server key and the salt are fresh per message; the
     * two optional arguments exist so the suite can supply Appendix A's and
     * get Appendix A's ciphertext back, byte for byte. The intermediates are
     * returned beside the body for the same reason: when a vector fails, the
     * step that failed is the diagnosis.
     *
     * @param string $p256dhB64 the browser's public key, base64url
     * @param string $authB64 the browser's 16-byte auth secret, base64url
     * @param string|null $asPrivateB64 the sender's 32-byte scalar; null = fresh
     * @param string|null $salt 16 bytes; null = fresh
     * @return array{body:string,ecdh_secret:string,ikm:string,cek:string,nonce:string,ciphertext:string,as_public:string}
     */
    public static function encrypt(
        string $plaintext,
        string $p256dhB64,
        string $authB64,
        ?string $asPrivateB64 = null,
        ?string $salt = null,
    ): array {
        $uaPublic = Vapid::b64urlDecode($p256dhB64);
        $authSecret = Vapid::b64urlDecode($authB64);
        if (\strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new \InvalidArgumentException('The subscription p256dh is not an uncompressed P-256 point.');
        }
        if (\strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('The subscription auth secret is not 16 bytes.');
        }
        $salt ??= \random_bytes(16);
        if (\strlen($salt) !== 16) {
            throw new \InvalidArgumentException('The salt is 16 bytes.');
        }

        // The sender's ephemeral pair.
        if ($asPrivateB64 === null) {
            $asKey = \openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
            if ($asKey === false) {
                throw new \RuntimeException('openssl could not make an ephemeral key.');
            }
            $asPublic = Vapid::pointOf($asKey);
        } else {
            // A known scalar: derive its point through openssl by signing
            // nothing -- openssl needs the point to build the key, so it is
            // computed from the scalar with a throwaway key object.
            $asPublic = self::pointFromScalar(Vapid::b64urlDecode($asPrivateB64));
            $asKey = Vapid::privateKey(['public' => Vapid::b64url($asPublic), 'private' => $asPrivateB64]);
        }

        // ECDH, then the two HKDF steps of RFC 8291 Section 3.3 and 3.4.
        $ecdhSecret = \openssl_pkey_derive(Vapid::publicKeyFromPoint($uaPublic), $asKey, 32);
        if (!\is_string($ecdhSecret) || \strlen($ecdhSecret) !== 32) {
            throw new \RuntimeException('ECDH failed: ' . (string) \openssl_error_string());
        }
        $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
        $ikm = \hash_hkdf('sha256', $ecdhSecret, 32, $keyInfo, $authSecret);

        // RFC 8188 Section 2: the content key and nonce from the IKM and salt.
        $cek = \hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = \hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // One record: the plaintext, then the 0x02 delimiter of a last record.
        $padded = $plaintext . "\x02";
        $tag = '';
        $ciphertext = \openssl_encrypt($padded, 'aes-128-gcm', $cek, \OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('AES-128-GCM failed: ' . (string) \openssl_error_string());
        }
        $ciphertext .= $tag;

        // RFC 8188 Section 2.1: salt, rs, idlen, keyid (the sender's point).
        $header = $salt . \pack('N', self::RECORD_SIZE) . \chr(65) . $asPublic;

        return [
            'body'        => $header . $ciphertext,
            'ecdh_secret' => $ecdhSecret,
            'ikm'         => $ikm,
            'cek'         => $cek,
            'nonce'       => $nonce,
            'ciphertext'  => $ciphertext,
            'as_public'   => $asPublic,
        ];
    }

    /**
     * The receiver's side of RFC 8291, for the suite: a real decrypt with the
     * browser's private key, so a test proves the bytes on the wire are what
     * a phone would show and not merely what the sender meant.
     */
    public static function decrypt(string $body, string $uaPrivateB64, string $uaPublicB64, string $authB64): string
    {
        if (\strlen($body) < 86 + 17) {
            throw new \InvalidArgumentException('Too short to be an aes128gcm message.');
        }
        $salt = \substr($body, 0, 16);
        $idlen = \ord($body[20]);
        $asPublic = \substr($body, 21, $idlen);
        $ciphertext = \substr($body, 21 + $idlen);

        $uaPublic = Vapid::b64urlDecode($uaPublicB64);
        $uaKey = Vapid::privateKey(['public' => $uaPublicB64, 'private' => $uaPrivateB64]);
        $ecdhSecret = \openssl_pkey_derive(Vapid::publicKeyFromPoint($asPublic), $uaKey, 32);
        if (!\is_string($ecdhSecret)) {
            throw new \RuntimeException('ECDH failed on the receiver side.');
        }
        $ikm = \hash_hkdf('sha256', $ecdhSecret, 32, "WebPush: info\x00" . $uaPublic . $asPublic, Vapid::b64urlDecode($authB64));
        $cek = \hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = \hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = \substr($ciphertext, -16);
        $plain = \openssl_decrypt(\substr($ciphertext, 0, -16), 'aes-128-gcm', $cek, \OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new \RuntimeException('The message did not authenticate.');
        }
        // Strip the delimiter and any padding after it.
        $at = \strrpos($plain, "\x02");
        return $at === false ? $plain : \substr($plain, 0, $at);
    }

    /**
     * The public point of a known scalar.
     *
     * openssl builds an EC key from x, y and d together and checks that the
     * point matches, so a scalar on its own cannot go in that way. It CAN go
     * in as an RFC 5915 ECPrivateKey with the public key omitted, which
     * openssl completes by multiplying the generator itself. Only the suite
     * reaches this, with RFC 8291's own sender key.
     */
    private static function pointFromScalar(string $d): string
    {
        if (\strlen($d) !== 32) {
            throw new \InvalidArgumentException('A P-256 scalar is 32 bytes.');
        }
        // An ECPrivateKey (RFC 5915) with no public key: SEQUENCE {
        //   INTEGER 1, OCTET STRING d, [0] OID prime256v1 }
        $oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $inner = "\x02\x01\x01" . "\x04\x20" . $d . "\xa0\x0a" . $oid;
        $der = "\x30" . \chr(\strlen($inner)) . $inner;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . \chunk_split(\base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
        $key = \openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('openssl refused the scalar: ' . (string) \openssl_error_string());
        }
        return Vapid::pointOf($key);
    }
}
