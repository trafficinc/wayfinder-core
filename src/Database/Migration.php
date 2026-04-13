<?php

declare(strict_types=1);

namespace Wayfinder\Database;

interface Migration
{
    public function up(Database $database): void;

    public function down(Database $database): void;
}
