<?php

declare(strict_types=1);

namespace Wayfinder\Security;

final class Encrypter
{
    private readonly string $key;

    public function __construct(
        string $key,
        private readonly string $cipher = 'AES-256-CBC',
    ) {
        $this->key = $this->parseKey($key);

        if ($this->key === '') {
            throw new \RuntimeException('Encryption key must not be empty.');
        }

        if (mb_strlen($this->key, '8bit') !== 32) {
            throw new \RuntimeException('Encryption key must be 32 bytes for AES-256-CBC.');
        }

        $supported = array_map('strtolower', openssl_get_cipher_methods());

        if (! in_array(strtolower($this->cipher), $supported, true)) {
            throw new \RuntimeException(sprintf('Cipher [%s] is not supported.', $this->cipher));
        }
    }

    public function encrypt(mixed $value): string
    {
        return $this->encryptString(serialize($value));
    }

    public function decrypt(string $payload): mixed
    {
        return unserialize($this->decryptString($payload));
    }

    public function encryptString(string $value): string
    {
        $ivLength = openssl_cipher_iv_length($this->cipher);

        if (! is_int($ivLength) || $ivLength < 1) {
            throw new \RuntimeException(sprintf('Unable to determine IV length for [%s].', $this->cipher));
        }

        $iv = random_bytes($ivLength);
        $ivEncoded = base64_encode($iv);
        $encrypted = openssl_encrypt($value, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);

        if (! is_string($encrypted)) {
            throw new \RuntimeException('Unable to encrypt the given value.');
        }

        $encryptedEncoded = base64_encode($encrypted);
        $mac = hash_hmac('sha256', $ivEncoded . $encryptedEncoded, $this->key);
        $payload = json_encode([
            'iv' => $ivEncoded,
            'value' => $encryptedEncoded,
            'mac' => $mac,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new \RuntimeException('Unable to encode encrypted payload.');
        }

        return base64_encode($payload);
    }

    public function decryptString(string $payload): string
    {
        $decoded = base64_decode($payload, true);

        if (! is_string($decoded)) {
            throw new \RuntimeException('Encrypted payload is not valid base64.');
        }

        $data = json_decode($decoded, true);

        if (
            ! is_array($data)
            || ! isset($data['iv'], $data['value'], $data['mac'])
            || ! is_string($data['iv'])
            || ! is_string($data['value'])
            || ! is_string($data['mac'])
        ) {
            throw new \RuntimeException('Encrypted payload is malformed.');
        }

        $expectedMac = hash_hmac('sha256', $data['iv'] . $data['value'], $this->key);

        if (! hash_equals($expectedMac, $data['mac'])) {
            throw new \RuntimeException('Encrypted payload MAC is invalid.');
        }

        $iv = base64_decode($data['iv'], true);
        $value = base64_decode($data['value'], true);

        if (! is_string($iv) || ! is_string($value)) {
            throw new \RuntimeException('Encrypted payload contains invalid base64 segments.');
        }

        $decrypted = openssl_decrypt($value, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);

        if (! is_string($decrypted)) {
            throw new \RuntimeException('Unable to decrypt the given payload.');
        }

        return $decrypted;
    }

    private function parseKey(string $key): string
    {
        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        if (! is_string($decoded)) {
            throw new \RuntimeException('APP_KEY base64 payload is invalid.');
        }

        return $decoded;
    }
}
