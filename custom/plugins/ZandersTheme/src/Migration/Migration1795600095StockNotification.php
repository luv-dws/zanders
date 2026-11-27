<?php declare(strict_types=1);

namespace ZandersTheme\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1795600095StockNotification extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795600095;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `zanders_stock_notification` (
                `id` BINARY(16) NOT NULL,
                `product_id` BINARY(16) NOT NULL,
                `product_version_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `email` VARCHAR(255) NOT NULL,
                `notified_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.zanders_stock_notification.product` (`product_id`, `product_version_id`, `notified_at`),
                KEY `idx.zanders_stock_notification.sales_channel` (`sales_channel_id`),
                KEY `idx.zanders_stock_notification.lookup` (`email`, `product_id`, `sales_channel_id`, `notified_at`),
                CONSTRAINT `fk.zanders_stock_notification.product` FOREIGN KEY (`product_id`, `product_version_id`)
                    REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.zanders_stock_notification.sales_channel` FOREIGN KEY (`sales_channel_id`)
                    REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }
}
