<?php declare(strict_types=1);

namespace ZandersTheme\Subscriber;

use Shopware\Core\Content\Product\Events\ProductCrossSellingIdsCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductCrossSellingStreamCriteriaEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CrossSellingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductCrossSellingIdsCriteriaEvent::class => 'filterOutOfStock',
            ProductCrossSellingStreamCriteriaEvent::class => 'filterOutOfStock',
        ];
    }

    /**
     * @param ProductCrossSellingIdsCriteriaEvent|ProductCrossSellingStreamCriteriaEvent $event
     */
    public function filterOutOfStock($event): void
    {
        $criteria = $event->getCriteria();

        // Ensure all required associations are loaded for cross-selling product cards
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('prices');

        // Note: Database-level stock filtering is omitted so out-of-stock items can be
        // loaded and dynamically toggled via the storefront Show/Hide toggle button.
    }
}
