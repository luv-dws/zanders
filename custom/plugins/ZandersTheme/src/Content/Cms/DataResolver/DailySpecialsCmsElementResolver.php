<?php declare(strict_types=1);

namespace ZandersTheme\Content\Cms\DataResolver;

use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Cms\SalesChannel\Struct\ProductSliderStruct;
use ZandersTheme\Service\ZandersDynamicProductService;

class DailySpecialsCmsElementResolver extends AbstractCmsElementResolver
{
    public const TYPE = 'zanders-daily-specials';

    public function __construct(
        private readonly ZandersDynamicProductService $dynamicProductService
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        return null;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $salesChannelContext = $resolverContext->getSalesChannelContext();
        $products = $this->dynamicProductService->getDailySpecials($salesChannelContext, 24);

        $slider = new ProductSliderStruct();
        $slider->setProducts($products);
        $slot->setData($slider);
    }
}
