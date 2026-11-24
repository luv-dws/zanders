<?php declare(strict_types=1);

namespace ZandersTheme\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ZandersDynamicProductService
{
    /**
     * @param SalesChannelRepository<ProductCollection> $salesChannelProductRepository
     */
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly SalesChannelRepository $salesChannelProductRepository,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function getDailySpecials(SalesChannelContext $context, int $limit = 24): ProductCollection
    {
        return $this->getProductsByConfigKey('zandersDailySpecials', $context, $limit);
    }

    public function getPopularProducts(SalesChannelContext $context, int $limit = 24): ProductCollection
    {
        return $this->getProductsByConfigKey('popularProducts', $context, $limit);
    }

    public function getProductsByConfigKey(
        string $configKey,
        SalesChannelContext $context,
        int $limit = 24
    ): ProductCollection {
        $salesChannelId = $context->getSalesChannelId();

        $rawConfig = $this->systemConfigService->get("ZandersTheme.config.{$configKey}", $salesChannelId);

        $identifiers = [];
        if (is_string($rawConfig) && trim($rawConfig) !== '') {
            // Split by commas, semicolons, whitespace, or newlines
            $identifiers = preg_split('/[\s,;]+/', trim($rawConfig), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($rawConfig)) {
            $identifiers = $rawConfig;
        }

        if (!empty($identifiers)) {
            $products = $this->getProductsByIdentifiers($identifiers, $context, $limit);
            if ($products->count() > 0) {
                return $products;
            }
        }

        // Default fallback: Return active products from catalog so the section is not empty
        return $this->getFallbackProducts($context, $limit);
    }

    public function getProductsByIdentifiers(
        array $identifiers,
        SalesChannelContext $context,
        int $limit = 24
    ): ProductCollection {
        $idList = array_values(array_filter($identifiers, static fn($s) => is_string($s) && trim($s) !== ''));
        if (empty($idList)) {
            return new ProductCollection();
        }

        $idList = array_values(array_unique($idList));
        $uuids = array_values(array_filter($idList, static fn(string $val): bool => Uuid::isValid($val)));
        $skus = array_values(array_filter($idList, static fn(string $val): bool => !Uuid::isValid($val)));

        $criteria = new Criteria();
        $criteria->setLimit($limit);
        if (!empty($uuids) && !empty($skus)) {
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new EqualsAnyFilter('id', $uuids),
                new EqualsAnyFilter('productNumber', $skus),
            ]));
        } elseif (!empty($uuids)) {
            $criteria->addFilter(new EqualsAnyFilter('id', $uuids));
        } else {
            $criteria->addFilter(new EqualsAnyFilter('productNumber', $skus));
        }

        $this->addStandardAssociations($criteria);

        /** @var ProductCollection $products */
        $products = $this->salesChannelProductRepository->search($criteria, $context)->getEntities();

        // Sort products to match configured order
        $idOrder = array_flip($idList);
        $products->sort(function ($a, $b) use ($idOrder) {
            $posA = $idOrder[$a->getId()] ?? $idOrder[$a->getProductNumber()] ?? 9999;
            $posB = $idOrder[$b->getId()] ?? $idOrder[$b->getProductNumber()] ?? 9999;
            return $posA <=> $posB;
        });

        return $products;
    }

    public function getFallbackProducts(
        SalesChannelContext $context,
        int $limit = 24
    ): ProductCollection {
        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $this->addStandardAssociations($criteria);

        /** @var ProductCollection $products */
        return $this->salesChannelProductRepository->search($criteria, $context)->getEntities();
    }

    private function addStandardAssociations(Criteria $criteria): void
    {
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('properties.group');
    }
}
