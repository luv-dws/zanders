<?php declare(strict_types=1);

namespace ZandersTheme\Subscriber;

use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;

class ProductStockFilterSubscriber implements EventSubscriberInterface
{
    public const CONFIG_KEY = 'ZandersTheme.config.hideOutOfStockProducts';

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingCriteriaEvent::class => 'onProductListingCriteria',
            ProductSearchCriteriaEvent::class => 'onProductSearchCriteria',
            ProductSearchResultEvent::class => 'onProductSearchResult',
        ];
    }

    /**
     * Applies filter to catalog / category listings
     */
    public function onProductListingCriteria(ProductListingCriteriaEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        if (!$this->isHideOutOfStockEnabled($salesChannelId, $event->getRequest())) {
            return;
        }

        $this->applyStockFilter($event->getCriteria());
    }

    /**
     * Applies filter to search criteria (before query execution for accurate pagination)
     */
    public function onProductSearchCriteria(ProductSearchCriteriaEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        if (!$this->isHideOutOfStockEnabled($salesChannelId, $event->getRequest())) {
            return;
        }

        $this->applyStockFilter($event->getCriteria());
    }

    /**
     * Handles search result event to ensure out-of-stock items are removed from search results
     */
    public function onProductSearchResult(ProductSearchResultEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        if (!$this->isHideOutOfStockEnabled($salesChannelId, $event->getRequest())) {
            return;
        }

        // Apply filter to criteria attached to the result
        $this->applyStockFilter($event->getResult()->getCriteria());

        // Ensure entities in the result collection are also filtered
        $result = $event->getResult();
        $filteredEntities = $result->getEntities()->filter(function ($product) {
            $availableStock = $product->getAvailableStock() ?? $product->getStock() ?? 0;
            return $availableStock > 0;
        });

        $result->setEntities($filteredEntities);
    }

    /**
     * Applies criteria: availableStock > 0
     */
    private function applyStockFilter(Criteria $criteria): void
    {
        $criteria->addFilter(
            new RangeFilter(
                'availableStock',
                [
                    RangeFilter::GT => 0,
                ]
            )
        );
    }

    /**
     * Checks if hiding out-of-stock items is active (via System Config or storefront request parameter)
     */
    private function isHideOutOfStockEnabled(string $salesChannelId, ?Request $request = null): bool
    {
        // 1. Check Storefront request override if toggled via URL (?hideOutOfStock=1 or ?hideOutOfStock=0)
        if ($request && $request->query->has('hideOutOfStock')) {
            return $request->query->getBoolean('hideOutOfStock');
        }

        // 2. Fallback to System Configuration setting
        return $this->systemConfigService->getBool(self::CONFIG_KEY, $salesChannelId);
    }
}
