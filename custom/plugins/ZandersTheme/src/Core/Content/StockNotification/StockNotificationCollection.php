<?php declare(strict_types=1);

namespace ZandersTheme\Core\Content\StockNotification;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<StockNotificationEntity>
 */
class StockNotificationCollection extends EntityCollection
{
    public function getExpectedClass(): string
    {
        return StockNotificationEntity::class;
    }
}
