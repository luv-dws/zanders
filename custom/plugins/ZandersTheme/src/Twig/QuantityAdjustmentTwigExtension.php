<?php declare(strict_types=1);

namespace ZandersTheme\Twig;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Adapter\Twig\TwigContextHelper;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use ZandersTheme\Service\QuantityAdjustmentService;

class QuantityAdjustmentTwigExtension extends AbstractExtension
{
    public function __construct(private readonly QuantityAdjustmentService $quantityAdjustmentService)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'zanders_quantity_adjustment',
                $this->getAdjustment(...),
                ['needs_context' => true]
            ),
        ];
    }

    /**
     * @param array<string,mixed> $twigContext
     * @return array{from:int,to:int,step:int,display:string,source:string|null}
     */
    public function getAdjustment(array $twigContext, ProductEntity|string $product): array
    {
        $salesChannelContext = TwigContextHelper::getSalesChannelContext($twigContext);
        if (!$salesChannelContext instanceof SalesChannelContext) {
            return ['from' => 1, 'to' => 100, 'step' => 1, 'display' => '', 'source' => null];
        }

        return $this->quantityAdjustmentService->resolve($product, $salesChannelContext);
    }
}
