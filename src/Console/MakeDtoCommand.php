<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class MakeDtoCommand extends AbstractMakeDataClassCommand
{
    public function name(): string
    {
        return 'make:dto';
    }

    public function description(): string
    {
        return 'Create a new DTO class.';
    }

    protected function directoryName(): string
    {
        return 'DTOs';
    }

    protected function classSuffix(): string
    {
        return 'Data';
    }

    protected function resourceLabel(): string
    {
        return 'DTO';
    }

    protected function template(string $namespace, string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Wayfinder\Database\DataTransferObject;

final class {$className} extends DataTransferObject
{
}
PHP;
    }
}
