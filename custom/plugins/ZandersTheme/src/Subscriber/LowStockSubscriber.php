<?php declare(strict_types=1);

namespace ZandersTheme\Subscriber;

use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Content\Product\Events\ProductStockAlteredEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use ZandersTheme\Service\LowStockAlertService;

class LowStockSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LowStockAlertService $lowStockAlertService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
            ProductStockAlteredEvent::class => 'onStockAltered',
        ];
    }

    /**
     * Triggered when an order is successfully placed in checkout.
     */
    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $this->lowStockAlertService->handleOrderPlaced($event);
    }

    /**
     * Triggered when product stock is altered (e.g. ERP updates, admin manual stock changes).
     */
    public function onStockAltered(ProductStockAlteredEvent $event): void
    {
        $this->lowStockAlertService->handleStockAltered($event);
    }
}
