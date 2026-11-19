<?php declare(strict_types=1);

namespace ZandersTheme\Twig;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Adapter\Twig\TwigContextHelper;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use ZandersTheme\Service\ProductRegionRestrictionService;
use ZandersTheme\Service\ProductPurchaseRestrictionService;

class ProductRestrictionTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProductRegionRestrictionService $restrictionService,
        private readonly ProductPurchaseRestrictionService $purchaseRestrictionService,
        private readonly ?RequestStack $requestStack = null
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'zanders_is_product_restricted',
                $this->isProductRestricted(...),
                ['needs_context' => true]
            ),
            new TwigFunction(
                'zanders_can_customer_purchase',
                $this->canCustomerPurchase(...),
                ['needs_context' => true]
            ),
            new TwigFunction(
                'zanders_purchase_restriction_reason',
                $this->getPurchaseRestrictionReason(...),
                ['needs_context' => true]
            ),
        ];
    }

    /**
     * @param array<string, mixed> $twigContext
     */
    public function isProductRestricted(
        array $twigContext,
        mixed $product,
        ?SalesChannelContext $salesChannelContext = null
    ): bool {
        if (!$salesChannelContext instanceof SalesChannelContext) {
            $salesChannelContext = TwigContextHelper::getSalesChannelContext($twigContext);
        }

        if (!$salesChannelContext instanceof SalesChannelContext && $this->requestStack !== null) {
            $request = $this->requestStack->getMainRequest() ?? $this->requestStack->getCurrentRequest();
            $requestContext = $request?->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
            if ($requestContext instanceof SalesChannelContext) {
                $salesChannelContext = $requestContext;
            }
        }

        if (!$salesChannelContext instanceof SalesChannelContext) {
            return false;
        }

        if ($product instanceof ProductEntity) {
            return $this->restrictionService->isProductRestricted($product, $salesChannelContext);
        }

        if (\is_string($product) && $product !== '') {
            return $this->restrictionService->isProductRestricted($product, $salesChannelContext);
        }

        if (\is_array($product) && isset($product['id']) && \is_string($product['id'])) {
            return $this->restrictionService->isProductRestricted($product['id'], $salesChannelContext);
        }

        return false;
    }

    public function canCustomerPurchase(
        array $twigContext,
        ?SalesChannelContext $salesChannelContext = null
    ): bool {
        if (!$salesChannelContext instanceof SalesChannelContext) {
            $salesChannelContext = TwigContextHelper::getSalesChannelContext($twigContext);
        }

        if (!$salesChannelContext instanceof SalesChannelContext && $this->requestStack !== null) {
            $request = $this->requestStack->getMainRequest() ?? $this->requestStack->getCurrentRequest();
            $requestContext = $request?->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
            if ($requestContext instanceof SalesChannelContext) {
                $salesChannelContext = $requestContext;
            }
        }

        if (!$salesChannelContext instanceof SalesChannelContext) {
            return false;
        }

        return !$this->purchaseRestrictionService->isPurchaseRestricted($salesChannelContext);
    }

    public function getPurchaseRestrictionReason(
        array $twigContext,
        ?SalesChannelContext $salesChannelContext = null
    ): string {
        if (!$salesChannelContext instanceof SalesChannelContext) {
            $salesChannelContext = TwigContextHelper::getSalesChannelContext($twigContext);
        }

        if (!$salesChannelContext instanceof SalesChannelContext && $this->requestStack !== null) {
            $request = $this->requestStack->getMainRequest() ?? $this->requestStack->getCurrentRequest();
            $requestContext = $request?->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
            if ($requestContext instanceof SalesChannelContext) {
                $salesChannelContext = $requestContext;
            }
        }

        if (!$salesChannelContext instanceof SalesChannelContext) {
            return 'Account eligibility could not be verified.';
        }

        return $this->purchaseRestrictionService->getRestrictionReason($salesChannelContext);
    }
}
