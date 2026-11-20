<?php declare(strict_types=1);

namespace ZandersTheme\Subscriber;

use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use ZandersTheme\Service\ProductFinderService;

class ProductFinderSubscriber implements EventSubscriberInterface
{
    private ?array $cachedFilterOptions = null;

    public function __construct(
        private readonly ProductFinderService $finderService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingCriteriaEvent::class => 'onCriteria',
            ProductSearchCriteriaEvent::class => 'onCriteria',
            StorefrontRenderEvent::class => 'onStorefrontRender',
        ];
    }

    /**
     * Injects dynamic Product Finder filter options into all storefront views so the slide-out drawer has real data.
     */
    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        if ($event->getParameter('filterOptions') !== null) {
            return;
        }

        try {
            if ($this->cachedFilterOptions === null) {
                $this->cachedFilterOptions = $this->finderService->getFilterOptions($event->getSalesChannelContext());
            }
            $event->setParameter('filterOptions', $this->cachedFilterOptions);
        } catch (\Throwable) {
            // Keep existing template fallbacks if options cannot be loaded
        }
    }

    /**
     * Applies dedicated Product Finder filter criteria when parameters are present on listing or search requests.
     */
    public function onCriteria(ProductListingCriteriaEvent|ProductSearchCriteriaEvent $event): void
    {
        $request = $event->getRequest();

        $hasFinderParams = $request->query->has('make')
            || $request->query->has('manufacturer')
            || $request->query->has('caliber')
            || $request->query->has('gauge')
            || $request->query->has('stockMaterial')
            || $request->query->has('frameMaterial')
            || $request->query->has('typeAction')
            || $request->query->has('action')
            || $request->query->has('typeSights')
            || $request->query->has('sights')
            || $request->query->has('model')
            || $request->query->has('year')
            || ($request->query->has('type') && $request->query->get('type') !== 'all')
            || $request->query->has('minPrice')
            || $request->query->has('maxPrice');

        if (!$hasFinderParams) {
            return;
        }

        $this->finderService->applyFinderFilters(
            $event->getCriteria(),
            $request,
            $event->getSalesChannelContext()
        );
    }
}
