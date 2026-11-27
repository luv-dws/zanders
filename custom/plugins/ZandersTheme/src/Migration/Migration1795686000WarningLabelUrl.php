<?php declare(strict_types=1);

namespace ZandersTheme\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1795686000WarningLabelUrl extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795686000;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('zanders_warning_label');
        if (!isset($columns['url'])) {
            $connection->executeStatement(<<<SQL
                ALTER TABLE `zanders_warning_label`
                ADD COLUMN `url` VARCHAR(2048) NULL AFTER `message`;
            SQL);
        }
    }
}
