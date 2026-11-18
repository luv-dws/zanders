<?php declare(strict_types=1);

namespace ZandersTheme\Subscriber;

use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use ZandersTheme\Cart\Error\ProductRegionRestrictionError;
use ZandersTheme\Service\ProductRegionRestrictionService;

#[Package('checkout')]
class ProductRestrictionCartSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ProductRegionRestrictionService $restrictionService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemAddedEvent::class => 'onBeforeLineItemAdded',
        ];
    }

    public function onBeforeLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $lineItem = $event->getLineItem();
        if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
            return;
        }

        $productId = $lineItem->getReferencedId();
        if ($productId === null) {
            return;
        }

        $context = $event->getSalesChannelContext();
        if (!$this->restrictionService->isProductRestricted($productId, $context)) {
            return;
        }

        $cart = $event->getCart();

        // Remove the restricted item from the cart
        $cart->getLineItems()->removeElement($lineItem);
        if ($cart->has($lineItem->getId())) {
            $cart->getLineItems()->remove($lineItem->getId());
        }

        // Add the restriction error
        $cart->addErrors(
            new ProductRegionRestrictionError(
                $productId,
                (string) ($lineItem->getLabel() ?? 'Product')
            )
        );
    }
}
