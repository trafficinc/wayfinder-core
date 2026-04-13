<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Security;

use PHPUnit\Framework\TestCase;
use Wayfinder\Http\Request;
use Wayfinder\Security\UrlSigner;
use Wayfinder\Security\ValidateSignature;

final class UrlSignerTest extends TestCase
{
    private UrlSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new UrlSigner('test-signing-key', 'https://app.example.test');
    }

    public function testGeneratesAbsoluteSignedUrl(): void
    {
        $url = $this->signer->sign('/download', ['file' => 'report']);

        self::assertStringStartsWith('https://app.example.test/download?', $url);
        self::assertStringContainsString('file=report', $url);
        self::assertStringContainsString('signature=', $url);
    }

    public function testSignedUrlValidates(): void
    {
        $url = $this->signer->sign('/download', ['file' => 'report']);

        self::assertTrue($this->signer->hasValidSignature($url));
    }

    public function testTamperedSignedUrlFailsValidation(): void
    {
        $url = $this->signer->sign('/download', ['file' => 'report']);
        $tampered = str_replace('file=report', 'file=other', $url);

        self::assertFalse($this->signer->hasValidSignature($tampered));
    }

    public function testExpiredSignedUrlFailsValidation(): void
    {
        $url = $this->signer->sign('/download', ['file' => 'report'], time() - 10);

        self::assertFalse($this->signer->hasValidSignature($url));
    }

    public function testRequestInstanceValidatesAgainstQueryAndPath(): void
    {
        $url = $this->signer->sign('/download', ['file' => 'report'], null, false);
        $queryString = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($queryString, $query);

        $request = new Request(
            'GET',
            '/download',
            $query,
            [],
            [],
            [],
            [],
            [],
            '',
        );

        self::assertTrue($this->signer->hasValidSignature($request));
    }

    public function testValidateSignatureMiddlewareRejectsUnsignedRequest(): void
    {
        $middleware = new ValidateSignature($this->signer);
        $request = new Request('GET', '/download', ['file' => 'report'], [], [], [], [], [], '');

        $response = $middleware->handle($request, static fn () => throw new \RuntimeException('should not run'));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('Invalid signature', $response->content());
    }
}
