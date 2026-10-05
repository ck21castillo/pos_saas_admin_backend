<?php

declare(strict_types=1);

namespace PosAdmin\Service;

use LogicException;
use PDO;
use RuntimeException;

final class LandingAnalyticsSchemaGuard
{
    /** @var (callable(): bool)|null */
    private $tableExists;

    /** @param (callable(): bool)|null $tableExists */
    public function __construct(?callable $tableExists = null)
    {
        $this->tableExists = $tableExists;
    }

    public function assertAvailable(?PDO $pdo = null): void
    {
        if ($this->tableExists !== null) {
            if (!(bool)($this->tableExists)()) {
                throw new RuntimeException('LANDING_ANALYTICS_SCHEMA_MISSING');
            }
            return;
        }

        if ($pdo === null) {
            throw new LogicException('LANDING_ANALYTICS_CONNECTION_REQUIRED');
        }

        $exists = $pdo->query("SELECT to_regclass('admin.landing_visit') IS NOT NULL")->fetchColumn();
        if (!$exists) {
            throw new RuntimeException('LANDING_ANALYTICS_SCHEMA_MISSING');
        }
    }
}
