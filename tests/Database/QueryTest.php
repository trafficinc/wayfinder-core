<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Database;

use PHPUnit\Framework\TestCase;
use Wayfinder\Database\DataTransferObject;
use Wayfinder\Database\Query;
use Wayfinder\Tests\Concerns\UsesDatabase;

final class QueryTest extends TestCase
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

    public function testQueryMapsResultsIntoDtos(): void
    {
        $this->db->insert('users', [
            'email' => 'admin@example.com',
            'password' => 'secret',
            'is_admin' => 1,
        ]);
        $this->db->insert('users', [
            'email' => 'member@example.com',
            'password' => 'secret',
            'is_admin' => 0,
        ]);

        $query = new AdminUserEmailsQuery($this->db);
        $results = $query->all();

        self::assertCount(1, $results);
        self::assertContainsOnlyInstancesOf(AdminUserEmailData::class, $results);
        self::assertSame('admin@example.com', $results[0]->email);
        self::assertSame(1, $results[0]->is_admin);
    }

    public function testQueryCanMapSingleResultIntoDto(): void
    {
        $this->db->insert('users', [
            'email' => 'owner@example.com',
            'password' => 'secret',
            'is_admin' => 1,
        ]);

        $query = new AdminUserEmailsQuery($this->db);
        $result = $query->firstAdmin();

        self::assertInstanceOf(AdminUserEmailData::class, $result);
        self::assertSame('owner@example.com', $result?->email);
    }
}

final class AdminUserEmailsQuery extends Query
{
    /**
     * @return list<AdminUserEmailData>
     */
    public function all(): array
    {
        return $this->many(
            AdminUserEmailData::class,
            'SELECT email, is_admin FROM users WHERE is_admin = ? ORDER BY id ASC',
            [1],
        );
    }

    public function firstAdmin(): ?AdminUserEmailData
    {
        /** @var AdminUserEmailData|null */
        return $this->one(
            AdminUserEmailData::class,
            'SELECT email, is_admin FROM users WHERE is_admin = ? ORDER BY id ASC LIMIT 1',
            [1],
        );
    }
}

final class AdminUserEmailData extends DataTransferObject
{
}
