<?php declare(strict_types=1);

namespace ZandersTheme\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use ZandersTheme\Cart\Error\ProductRegionRestrictionError;
use ZandersTheme\Service\ProductRegionRestrictionService;

#[Package('checkout')]
class ProductRegionCartValidator implements CartValidatorInterface
{
    public function __construct(
        private readonly ProductRegionRestrictionService $restrictionService
    ) {
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

            if ($this->restrictionService->isProductRestricted($productId, $context)) {
                $name = (string) ($lineItem->getLabel() ?? 'Product');
                $errors->add(new ProductRegionRestrictionError($productId, $name));
            }
        }
    }
}
