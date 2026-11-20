<?php declare(strict_types=1);

namespace ZandersTheme\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use ZandersTheme\Cart\Error\QuantityAdjustmentError;
use ZandersTheme\Service\QuantityAdjustmentService;

#[Package('checkout')]
class QuantityAdjustmentCartValidator implements CartValidatorInterface
{
    public function __construct(private readonly QuantityAdjustmentService $quantityAdjustmentService)
    {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $productId = $lineItem->getReferencedId();
            if ($productId === null) {
                continue;
            }

            $reason = $this->quantityAdjustmentService->validateQuantity(
                $productId,
                $lineItem->getQuantity(),
                $context
            );

            if ($reason !== null) {
                $errors->add(new QuantityAdjustmentError(
                    $productId,
                    (string) ($lineItem->getLabel() ?? 'Product'),
                    $reason
                ));
            }
        }
    }
}
