<?php declare(strict_types=1);

namespace ZandersTheme\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1795674095WarningLabel extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795674095;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `zanders_warning_label` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `message` LONGTEXT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }
}
