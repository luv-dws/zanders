<?php declare(strict_types=1);

namespace ZandersTheme\Twig;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Adapter\Twig\TwigContextHelper;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use ZandersTheme\Service\ZandersDynamicProductService;

class ZandersDynamicProductTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ZandersDynamicProductService $dynamicProductService
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('zanders_get_daily_specials', $this->getDailySpecials(...), ['needs_context' => true]),
            new TwigFunction('zanders_get_popular_products', $this->getPopularProducts(...), ['needs_context' => true]),
            new TwigFunction('zanders_get_products_by_stream', $this->getProductsByStream(...), ['needs_context' => true]),
            new TwigFunction('zanders_get_products_by_category', $this->getProductsByCategory(...), ['needs_context' => true]),
        ];
    }

    /**
     * @param array<string, mixed> $twigContext
     */
    public function getDailySpecials(array $twigContext, int $limit = 12): ProductCollection
    {
        $salesChannelContext = TwigContextHelper::getSalesChannelContext($twigContext);
        if (!$salesChannelContext instanceof SalesChannelContext) {
            return new ProductCollection();
        }

        return $this->dynamicProductService->getDailySpecials($salesChannelContext, $limit);
    }

    /**
     * @param array<string, mixed> $twigContext
     */
    public function getPopularProducts(array $twigContext, int $limit = 12): ProductCollection
    {
        $salesChannelContext = TwigContextHelper::getSalesChannelContext($twigContext);
        if (!$salesChannelContext instanceof SalesChannelContext) {
            return new ProductCollection();
        }

        return $this->dynamicProductService->getPopularProducts($salesChannelContext, $limit);
    }

    /**
     * @param array<string, mixed> $twigContext
     */
    public function getProductsByStream(array $twigContext, string $streamId, int $limit = 12): ProductCollection
    {
        $salesChannelContext = TwigContextHelper::getSalesChannelContext($twigContext);
        if (!$salesChannelContext instanceof SalesChannelContext) {
            return new ProductCollection();
        }

        return $this->dynamicProductService->getProductsByStream($streamId, $salesChannelContext, $limit);
    }

    /**
     * @param array<string, mixed> $twigContext
     */
    public function getProductsByCategory(array $twigContext, string $categoryId, int $limit = 12): ProductCollection
    {
        $salesChannelContext = TwigContextHelper::getSalesChannelContext($twigContext);
        if (!$salesChannelContext instanceof SalesChannelContext) {
            return new ProductCollection();
        }

        return $this->dynamicProductService->getProductsByCategory($categoryId, $salesChannelContext, $limit);
    }
}
