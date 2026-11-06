<?php declare(strict_types=1);

namespace App\Twig;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Adapter\Twig\TwigContextHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ZandersProductExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire(service: 'sales_channel.product.repository')]
        private readonly SalesChannelRepository $salesChannelProductRepository
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('zanders_products_by_skus', $this->getProductsBySkus(...), ['needs_context' => true]),
        ];
    }

    /**
     * @param array<string, mixed> $twigContext
     * @param mixed $skus
     */
    public function getProductsBySkus(array $twigContext, mixed $skus): ProductCollection
    {
        $salesChannelContext = TwigContextHelper::getSalesChannelContext($twigContext);
        if (!$salesChannelContext instanceof SalesChannelContext) {
            return new ProductCollection();
        }

        $skuList = [];
        if (is_array($skus)) {
            $skuList = array_values(array_filter($skus, fn($s) => is_string($s) && trim($s) !== ''));
        }

        if (empty($skuList)) {
            return new ProductCollection();
        }

        $skuList = array_values(array_unique($skuList));

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('productNumber', $skuList));
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('prices');

        /** @var ProductCollection $products */
        $products = $this->salesChannelProductRepository->search($criteria, $salesChannelContext)->getEntities();

        // Sort products to match the configured order of SKUs
        $skuOrder = array_flip($skuList);
        $products->sort(function ($a, $b) use ($skuOrder) {
            $posA = $skuOrder[$a->getProductNumber()] ?? 9999;
            $posB = $skuOrder[$b->getProductNumber()] ?? 9999;
            return $posA <=> $posB;
        });

        return $products;
    }
}

