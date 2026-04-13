<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Session;

use PHPUnit\Framework\TestCase;
use Wayfinder\Session\Session;

final class SessionTest extends TestCase
{
    public function testNewSessionIsNotDirtyAndNotExisting(): void
    {
        $session = new Session('abc123');

        self::assertFalse($session->isDirty());
        self::assertFalse($session->exists());
    }

    public function testGetReturnsDefault(): void
    {
        $session = new Session('abc123');

        self::assertNull($session->get('missing'));
        self::assertSame('default', $session->get('missing', 'default'));
    }

    public function testPutAndGet(): void
    {
        $session = new Session('abc123');
        $session->put('user', 'ron');

        self::assertSame('ron', $session->get('user'));
        self::assertTrue($session->isDirty());
    }

    public function testHas(): void
    {
        $session = new Session('abc123', ['key' => null]);

        self::assertTrue($session->has('key'));
        self::assertFalse($session->has('other'));
    }

    public function testPullReturnsValueAndRemovesKey(): void
    {
        $session = new Session('abc123', ['token' => 'secret']);

        $value = $session->pull('token');

        self::assertSame('secret', $value);
        self::assertFalse($session->has('token'));
    }

    public function testPullReturnsDefaultForMissingKey(): void
    {
        $session = new Session('abc123');

        self::assertSame('fallback', $session->pull('missing', 'fallback'));
    }

    public function testForgetRemovesKey(): void
    {
        $session = new Session('abc123', ['key' => 'value']);
        $session->forget('key');

        self::assertFalse($session->has('key'));
        self::assertTrue($session->isDirty());
    }

    public function testForgetMissingKeyDoesNotMarkDirty(): void
    {
        $session = new Session('abc123');
        $session->forget('nonexistent');

        self::assertFalse($session->isDirty());
    }

    public function testFlushClearsAllData(): void
    {
        $session = new Session('abc123', ['a' => 1, 'b' => 2]);
        $session->flush();

        self::assertSame([], $session->all());
        self::assertTrue($session->isDirty());
    }

    public function testFlashStoresValueAndTracksKey(): void
    {
        $session = new Session('abc123');
        $session->flash('status', 'Saved!');

        self::assertSame('Saved!', $session->get('status'));
    }

    public function testAgeFlashDataMakesFlashedValueAvailableForNextRequest(): void
    {
        $session = new Session('abc123');
        $session->flash('status', 'Saved!');

        // Simulates end-of-request age (moves new → old)
        $session->ageFlashData();

        // Value still accessible this request
        self::assertSame('Saved!', $session->get('status'));
    }

    public function testAgeFlashDataTwiceRemovesValue(): void
    {
        $session = new Session('abc123');
        $session->flash('status', 'Saved!');

        $session->ageFlashData(); // request 1 → old
        $session->ageFlashData(); // request 2 → purged

        self::assertFalse($session->has('status'));
    }

    public function testRegenerateChangesSessionId(): void
    {
        $session = new Session('original-id');
        $session->regenerate();

        self::assertNotSame('original-id', $session->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $session->id());
    }

    public function testRegenerateSetsPreviousIdByDefault(): void
    {
        $session = new Session('old-id');
        $session->regenerate();

        self::assertSame('old-id', $session->previousId());
    }

    public function testRegenerateWithoutDestroyDoesNotSetPreviousId(): void
    {
        $session = new Session('old-id');
        $session->regenerate(destroy: false);

        self::assertNull($session->previousId());
    }

    public function testRegenerateMarksSessionAsNotExisting(): void
    {
        $session = new Session('id', [], exists: true);
        $session->regenerate();

        self::assertFalse($session->exists());
    }

    public function testSyncAfterSaveClearsState(): void
    {
        $session = new Session('id');
        $session->put('key', 'val');
        $session->regenerate();

        self::assertTrue($session->isDirty());
        self::assertNotNull($session->previousId());

        $session->syncAfterSave();

        self::assertFalse($session->isDirty());
        self::assertTrue($session->exists());
        self::assertNull($session->previousId());
    }

    public function testMarkAsExistingUpdatesFlag(): void
    {
        $session = new Session('id');
        self::assertFalse($session->exists());

        $session->markAsExisting();
        self::assertTrue($session->exists());
    }
}
