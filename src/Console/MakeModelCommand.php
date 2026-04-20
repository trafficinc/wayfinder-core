<?php

declare(strict_types=1);

namespace Wayfinder\Console;

final class MakeModelCommand extends AbstractMakeDataClassCommand
{
    public function name(): string
    {
        return 'make:model';
    }

    public function description(): string
    {
        return 'Create a new model class.';
    }

    protected function directoryName(): string
    {
        return 'Models';
    }

    protected function classSuffix(): string
    {
        return '';
    }

    protected function resourceLabel(): string
    {
        return 'Model';
    }

    protected function template(string $namespace, string $className): string
    {
        $table = $this->inferTableName($className);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Wayfinder\Database\Model;

final class {$className} extends Model
{
    protected static string \$table = '{$table}';
}
PHP;
    }

    private function inferTableName(string $className): string
    {
        $segments = preg_split('/(?=[A-Z])/', $className, -1, PREG_SPLIT_NO_EMPTY) ?: [$className];
        $snake = strtolower(implode('_', $segments));

        return str_ends_with($snake, 's') ? $snake : $snake . 's';
    }
}
