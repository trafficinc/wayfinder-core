<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Database;

use PHPUnit\Framework\TestCase;
use Wayfinder\Database\Database;
use Wayfinder\Database\DatabaseManager;

final class DatabaseManagerTest extends TestCase
{
    public function testSupportsLegacySingleConnectionConfig(): void
    {
        $manager = new DatabaseManager([
            'default' => [
                'driver' => 'sqlite',
                'path' => ':memory:',
            ],
        ]);

        $database = $manager->connection();

        self::assertInstanceOf(Database::class, $database);
        self::assertSame($database, $manager->connection());
        self::assertSame('default', $manager->defaultConnectionName());
    }

    public function testResolvesNamedConnections(): void
    {
        $manager = new DatabaseManager([
            'default' => 'primary',
            'connections' => [
                'primary' => [
                    'driver' => 'sqlite',
                    'path' => ':memory:',
                ],
                'analytics' => [
                    'driver' => 'sqlite',
                    'path' => ':memory:',
                ],
            ],
        ]);

        $default = $manager->connection();
        $named = $manager->connection('analytics');

        self::assertInstanceOf(Database::class, $default);
        self::assertInstanceOf(Database::class, $named);
        self::assertNotSame($default, $named);
    }

    public function testThrowsForUnknownNamedConnection(): void
    {
        $manager = new DatabaseManager([
            'default' => 'primary',
            'connections' => [
                'primary' => [
                    'driver' => 'sqlite',
                    'path' => ':memory:',
                ],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database connection [reporting] is not configured.');

        $manager->connection('reporting');
    }
}
