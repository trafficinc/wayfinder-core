<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Security;

use PHPUnit\Framework\TestCase;
use Wayfinder\Security\Encrypter;

final class EncrypterTest extends TestCase
{
    private Encrypter $encrypter;

    protected function setUp(): void
    {
        $this->encrypter = new Encrypter('base64:' . base64_encode(random_bytes(32)));
    }

    public function testEncryptAndDecryptStringRoundTrips(): void
    {
        $payload = $this->encrypter->encryptString('secret-value');

        self::assertNotSame('secret-value', $payload);
        self::assertSame('secret-value', $this->encrypter->decryptString($payload));
    }

    public function testEncryptAndDecryptRoundTripsStructuredData(): void
    {
        $payload = $this->encrypter->encrypt([
            'name' => 'Wayfinder',
            'roles' => ['admin', 'editor'],
        ]);

        self::assertSame([
            'name' => 'Wayfinder',
            'roles' => ['admin', 'editor'],
        ], $this->encrypter->decrypt($payload));
    }

    public function testTamperedPayloadFailsMacValidation(): void
    {
        $payload = $this->encrypter->encryptString('secret-value');
        $decoded = base64_decode($payload, true);
        self::assertIsString($decoded);

        $data = json_decode($decoded, true);
        self::assertIsArray($data);
        $data['value'] = base64_encode('tampered');
        $tampered = base64_encode((string) json_encode($data, JSON_UNESCAPED_SLASHES));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MAC');
        $this->encrypter->decryptString($tampered);
    }

    public function testInvalidBase64PayloadFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('base64');
        $this->encrypter->decryptString('not-valid-base64');
    }

    public function testRejectsInvalidKeyLength(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('32 bytes');
        new Encrypter('short-key');
    }
}
