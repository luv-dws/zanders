<?php declare(strict_types=1);

namespace ZandersTheme\Subscriber;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\DataAbstractionLayer\Cache\EntityCacheKeyGenerator;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use ZandersTheme\Core\Content\WarningLabel\WarningLabelDefinition;

class WarningLabelCacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CacheInvalidator $cacheInvalidator,
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityWrittenContainerEvent::class => [
                ['onWarningLabelWritten', 2000],
            ],
            EntityDeleteEvent::class => [
                ['onWarningLabelDeleted', 2000],
            ],
        ];
    }

    public function onWarningLabelWritten(EntityWrittenContainerEvent $event): void
    {
        $rawKeys = [
            ...$event->getPrimaryKeys(WarningLabelDefinition::ENTITY_NAME),
            ...$event->getDeletedPrimaryKeys(WarningLabelDefinition::ENTITY_NAME),
        ];

        $cleanIds = [];
        foreach ($rawKeys as $key) {
            if (is_string($key) && $key !== '') {
                $cleanIds[] = $key;
            } elseif (is_array($key) && isset($key['id']) && is_string($key['id'])) {
                $cleanIds[] = $key['id'];
            }
        }

        $cleanIds = array_values(array_unique($cleanIds));

        if ($cleanIds === []) {
            return;
        }

        $this->invalidate($cleanIds);
    }

    public function onWarningLabelDeleted(EntityDeleteEvent $event): void
    {
        $ids = $event->getIds(WarningLabelDefinition::ENTITY_NAME);

        if ($ids === []) {
            return;
        }

        $cleanIds = [];
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $cleanIds[] = $id;
            } elseif (is_array($id)) {
                $first = reset($id);
                if (is_string($first) && $first !== '') {
                    $cleanIds[] = $first;
                }
            }
        }

        $cleanIds = array_values(array_unique($cleanIds));

        if ($cleanIds === []) {
            return;
        }

        // Before record is deleted from DB, query the affected product IDs
        $productTags = $this->getProductTagsForWarningLabels($cleanIds);
        $warningTags = array_map(static fn (string $id): string => 'zanders-warning-' . $id, $cleanIds);
        $tags = array_values(array_unique(array_merge($productTags, $warningTags)));

        $event->addSuccess(function () use ($tags): void {
            if ($tags !== []) {
                $this->cacheInvalidator->invalidate($tags, force: true);
            }
        });
    }

    /**
     * @param list<string> $warningLabelIds
     */
    private function invalidate(array $warningLabelIds): void
    {
        $productTags = $this->getProductTagsForWarningLabels($warningLabelIds);
        $warningTags = array_map(static fn (string $id): string => 'zanders-warning-' . $id, $warningLabelIds);

        $tags = array_values(array_unique(array_merge($productTags, $warningTags)));

        if ($tags === []) {
            return;
        }

        $this->cacheInvalidator->invalidate($tags, force: true);
    }

    /**
     * @param list<string> $warningLabelIds
     * @return list<string>
     */
    private function getProductTagsForWarningLabels(array $warningLabelIds): array
    {
        $cleanIds = array_values(array_filter(array_unique(array_map('strtolower', $warningLabelIds))));

        if ($cleanIds === []) {
            return [];
        }

        // 1. Query products that have any of these warning label IDs in their custom fields
        // (checking product and product_translation for both possible key names)
        $query = '
            SELECT DISTINCT
                LOWER(HEX(p.id)) AS product_id,
                IF(p.parent_id IS NULL, NULL, LOWER(HEX(p.parent_id))) AS parent_id
            FROM product p
            LEFT JOIN product_translation pt
                ON pt.product_id = p.id
                AND pt.product_version_id = p.version_id
            WHERE (
                LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.custom_fields, "$.zanders_warning_label_id"))) IN (:ids)
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(pt.custom_fields, "$.zanders_warning_label_id"))) IN (:ids)
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.custom_fields, "$.custom_zanders_warning_label_id"))) IN (:ids)
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(pt.custom_fields, "$.custom_zanders_warning_label_id"))) IN (:ids)
            )
            AND p.version_id = :version
        ';

        try {
            /** @var list<array{product_id: string, parent_id: string|null}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                $query,
                [
                    'ids' => $cleanIds,
                    'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                ],
                [
                    'ids' => ArrayParameterType::STRING,
                ]
            );
        } catch (\Throwable) {
            return [];
        }

        if ($rows === []) {
            return [];
        }

        $productIds = [];
        $parentIdsToFindVariants = [];

        foreach ($rows as $row) {
            $productIds[] = $row['product_id'];

            if ($row['parent_id'] !== null) {
                // If the product is a variant, invalidate the parent product tag too (PDP cache is tagged with parent)
                $productIds[] = $row['parent_id'];
            } else {
                // If the product is a parent/standalone product, collect it so we can find any of its variants
                $parentIdsToFindVariants[] = $row['product_id'];
            }
        }

        // 2. Also find variant IDs for matched parent products
        if ($parentIdsToFindVariants !== []) {
            $uniqueParentIds = array_values(array_unique($parentIdsToFindVariants));
            try {
                $variantIds = $this->connection->fetchFirstColumn(
                    'SELECT LOWER(HEX(id)) FROM product WHERE parent_id IN (:parentIds) AND version_id = :version',
                    [
                        'parentIds' => Uuid::fromHexToBytesList($uniqueParentIds),
                        'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                    ],
                    [
                        'parentIds' => ArrayParameterType::BINARY,
                    ]
                );

                foreach ($variantIds as $variantId) {
                    if (is_string($variantId) && $variantId !== '') {
                        $productIds[] = $variantId;
                    }
                }
            } catch (\Throwable) {
                // Gracefully ignore if variant query encounters an issue
            }
        }

        $uniqueProductIds = array_values(array_unique(array_filter($productIds)));

        return array_map(EntityCacheKeyGenerator::buildProductTag(...), $uniqueProductIds);
    }
}
