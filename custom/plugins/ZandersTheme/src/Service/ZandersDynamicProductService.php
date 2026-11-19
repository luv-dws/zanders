<?php declare(strict_types=1);

namespace ZandersTheme\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\ProductStream\Service\AbstractProductStreamBuilder;
use Shopware\Core\Content\ProductStream\Service\ProductStreamBuilderInterface;
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
        private readonly AbstractProductStreamBuilder|ProductStreamBuilderInterface $productStreamBuilder,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function getDailySpecials(SalesChannelContext $context, int $limit = 12): ProductCollection
    {
        return $this->getProductsByConfig('dailySpecials', $context, $limit, 'zandersDailySpecials');
    }

    public function getPopularProducts(SalesChannelContext $context, int $limit = 12): ProductCollection
    {
        return $this->getProductsByConfig('popularProducts', $context, $limit, 'popularProducts');
    }

    public function getProductsByConfig(
        string $prefix,
        SalesChannelContext $context,
        int $limit = 12,
        ?string $legacyKey = null
    ): ProductCollection {
        $salesChannelId = $context->getSalesChannelId();

        // 1. Try configured product stream (Dynamic Product Group)
        $streamId = $this->systemConfigService->getString("ZandersTheme.config.{$prefix}Stream", $salesChannelId);
        if ($streamId !== '' && Uuid::isValid($streamId)) {
            $products = $this->getProductsByStream($streamId, $context, $limit);
            if ($products->count() > 0) {
                return $products;
            }
        }

        // 2. Try configured category
        $categoryId = $this->systemConfigService->getString("ZandersTheme.config.{$prefix}Category", $salesChannelId);
        if ($categoryId !== '' && Uuid::isValid($categoryId)) {
            $products = $this->getProductsByCategory($categoryId, $context, $limit);
            if ($products->count() > 0) {
                return $products;
            }
        }

        // 3. Try configured manual/fallback product list
        $productIds = $this->systemConfigService->get("ZandersTheme.config.{$prefix}Products", $salesChannelId);
        if (!is_array($productIds) && $legacyKey !== null) {
            $productIds = $this->systemConfigService->get("ZandersTheme.config.{$legacyKey}", $salesChannelId);
        }

        if (is_array($productIds) && !empty($productIds)) {
            $products = $this->getProductsByIdentifiers($productIds, $context, $limit);
            if ($products->count() > 0) {
                return $products;
            }
        }

        // 4. Default fallback: Return active products from catalog so the slider is not empty
        return $this->getFallbackProducts($context, $limit);
    }

    public function getProductsByStream(
        string $streamId,
        SalesChannelContext $context,
        int $limit = 12
    ): ProductCollection {
        try {
            $criteria = new Criteria();
            $criteria->setLimit($limit);
            $this->addStandardAssociations($criteria);

            if ($this->productStreamBuilder instanceof AbstractProductStreamBuilder) {
                $this->productStreamBuilder->enrichCriteria($criteria, $streamId, $context->getContext());
            } else {
                $filters = $this->productStreamBuilder->buildFilters($streamId, $context->getContext());
                $criteria->addFilter(...$filters);
            }

            /** @var ProductCollection $products */
            $products = $this->salesChannelProductRepository->search($criteria, $context)->getEntities();
            return $products;
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to load products for stream: ' . $streamId, ['exception' => $e]);
            return new ProductCollection();
        }
    }

    public function getProductsByCategory(
        string $categoryId,
        SalesChannelContext $context,
        int $limit = 12
    ): ProductCollection {
        try {
            $criteria = new Criteria();
            $criteria->setLimit($limit);
            $criteria->addFilter(new EqualsAnyFilter('categoryTree', [$categoryId]));
            $criteria->addSorting(new FieldSorting('sales', FieldSorting::DESCENDING));
            $this->addStandardAssociations($criteria);

            /** @var ProductCollection $products */
            $products = $this->salesChannelProductRepository->search($criteria, $context)->getEntities();
            return $products;
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to load products for category: ' . $categoryId, ['exception' => $e]);
            return new ProductCollection();
        }
    }

    public function getProductsByIdentifiers(
        array $identifiers,
        SalesChannelContext $context,
        int $limit = 12
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
        int $limit = 12
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
    }
}
