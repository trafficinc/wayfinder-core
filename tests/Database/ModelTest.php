<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Database;

use PHPUnit\Framework\TestCase;
use Wayfinder\Database\Model;
use Wayfinder\Tests\Concerns\UsesDatabase;

final class ModelTest extends TestCase
{
    use UsesDatabase;

    protected function setUp(): void
    {
        $this->setUpDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    public function testModelHydratesTypedObjectInsteadOfReturningArray(): void
    {
        $this->db->insert('users', [
            'email' => 'ada@example.com',
            'password' => 'secret',
            'is_admin' => 1,
        ]);

        $user = TestUser::find(1);

        self::assertInstanceOf(TestUser::class, $user);
        self::assertSame('ada@example.com', $user?->email);
        self::assertSame(1, $user?->is_admin);
        self::assertTrue($user?->exists() ?? false);
    }

    public function testModelSupportsSimpleCrudOperations(): void
    {
        $created = TestUser::create([
            'email' => 'grace@example.com',
            'password' => 'secret',
            'is_admin' => 0,
        ]);

        self::assertInstanceOf(TestUser::class, $created);
        self::assertSame('grace@example.com', $created->email);
        self::assertSame(1, $created->getKey());

        $fetched = TestUser::where('email', 'grace@example.com')->first();
        self::assertInstanceOf(TestUser::class, $fetched);
        self::assertSame($created->getKey(), $fetched->getKey());

        $created->update([
            'email' => 'hopper@example.com',
            'is_admin' => 1,
        ]);

        $updated = TestUser::find($created->getKey());
        self::assertSame('hopper@example.com', $updated?->email);
        self::assertSame(1, $updated?->is_admin);

        self::assertTrue($created->delete());
        self::assertNull(TestUser::find($created->getKey()));
    }

    public function testModelAllReturnsTypedCollection(): void
    {
        $this->db->insert('users', [
            'email' => 'alpha@example.com',
            'password' => 'one',
            'is_admin' => 0,
        ]);
        $this->db->insert('users', [
            'email' => 'beta@example.com',
            'password' => 'two',
            'is_admin' => 1,
        ]);

        $users = TestUser::query()->orderBy('id')->get();

        self::assertCount(2, $users);
        self::assertContainsOnlyInstancesOf(TestUser::class, $users);
        self::assertSame(['alpha@example.com', 'beta@example.com'], array_map(
            static fn (TestUser $user): string => (string) $user->email,
            $users,
        ));
    }
}

final class TestUser extends Model
{
    protected static string $table = 'users';
}
