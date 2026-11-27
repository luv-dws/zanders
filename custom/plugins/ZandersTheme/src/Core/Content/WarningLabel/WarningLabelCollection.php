<?php declare(strict_types=1);

namespace ZandersTheme\Core\Content\WarningLabel;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<WarningLabelEntity>
 */
class WarningLabelCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return WarningLabelEntity::class;
    }
}