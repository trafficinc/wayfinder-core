<?php

declare(strict_types=1);

namespace Wayfinder\Database;

abstract class Query
{
    public function __construct(
        protected ?Database $database = null,
    ) {
        $this->database ??= DB::connection();
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    final protected function run(string $sql, array $bindings = []): array
    {
        return $this->database->query($sql, $bindings);
    }

    /**
     * @param list<mixed> $bindings
     * @return array<string, mixed>|null
     */
    final protected function firstRow(string $sql, array $bindings = []): ?array
    {
        $row = $this->database->firstResult($sql, $bindings);

        return $row === false ? null : $row;
    }

    /**
     * @template TDto of DataTransferObject
     * @param class-string<TDto> $dtoClass
     * @param list<mixed> $bindings
     * @return TDto|null
     */
    final protected function one(string $dtoClass, string $sql, array $bindings = []): ?DataTransferObject
    {
        return $this->mapOne($this->firstRow($sql, $bindings), $dtoClass);
    }

    /**
     * @template TDto of DataTransferObject
     * @param class-string<TDto> $dtoClass
     * @param list<mixed> $bindings
     * @return list<TDto>
     */
    final protected function many(string $dtoClass, string $sql, array $bindings = []): array
    {
        return $this->mapMany($this->run($sql, $bindings), $dtoClass);
    }

    /**
     * @template TDto of DataTransferObject
     * @param class-string<TDto> $dtoClass
     * @param array<string, mixed>|null $row
     * @return TDto|null
     */
    final protected function mapOne(?array $row, string $dtoClass): ?DataTransferObject
    {
        if ($row === null) {
            return null;
        }

        return $dtoClass::fromRow($row);
    }

    /**
     * @template TDto of DataTransferObject
     * @param class-string<TDto> $dtoClass
     * @param list<array<string, mixed>> $rows
     * @return list<TDto>
     */
    final protected function mapMany(array $rows, string $dtoClass): array
    {
        return array_map(
            static fn (array $row): DataTransferObject => $dtoClass::fromRow($row),
            $rows,
        );
    }
}
