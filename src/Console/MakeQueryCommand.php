<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class MakeQueryCommand extends AbstractMakeDataClassCommand
{
    public function name(): string
    {
        return 'make:query';
    }

    public function description(): string
    {
        return 'Create a new query class.';
    }

    protected function directoryName(): string
    {
        return 'Queries';
    }

    protected function classSuffix(): string
    {
        return 'Query';
    }

    protected function resourceLabel(): string
    {
        return 'Query';
    }

    protected function template(string $namespace, string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Wayfinder\Database\Query;

final class {$className} extends Query
{
    public function execute(): array
    {
        return [];
    }
}
PHP;
    }
}
