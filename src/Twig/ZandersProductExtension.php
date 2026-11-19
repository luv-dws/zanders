<?php declare(strict_types=1);

namespace App\Twig;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Adapter\Twig\TwigContextHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\Uuid\Uuid;
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
            new TwigFunction('zanders_get_products', $this->getProducts(...), ['needs_context' => true]),
        ];
    }

    /**
     * @param array<string, mixed> $twigContext
     */
    public function getProducts(array $twigContext, int $limit = 16): ProductCollection
    {
        $salesChannelContext = TwigContextHelper::getSalesChannelContext($twigContext);
        if (!$salesChannelContext instanceof SalesChannelContext) {
            return new ProductCollection();
        }

        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('prices');

        /** @var ProductCollection $products */
        return $this->salesChannelProductRepository->search($criteria, $salesChannelContext)->getEntities();
    }

    /**
     * @param array<string, mixed> $twigContext
     * @param mixed $identifiers
     */
    public function getProductsBySkus(array $twigContext, mixed $identifiers): ProductCollection
    {
        $salesChannelContext = TwigContextHelper::getSalesChannelContext($twigContext);
        if (!$salesChannelContext instanceof SalesChannelContext) {
            return new ProductCollection();
        }

        $idList = [];
        if (is_array($identifiers)) {
            $idList = array_values(array_filter($identifiers, static fn($s) => is_string($s) && trim($s) !== ''));
        }

        if (empty($idList)) {
            return new ProductCollection();
        }

        $idList = array_values(array_unique($idList));

        $uuids = array_values(array_filter($idList, static fn(string $val): bool => Uuid::isValid($val)));
        $skus = array_values(array_filter($idList, static fn(string $val): bool => !Uuid::isValid($val)));

        $criteria = new Criteria();
        if (!empty($uuids) && !empty($skus)) {
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new EqualsAnyFilter('id', $uuids),
                new EqualsAnyFilter('productNumber', $skus),
            ]));
        } elseif (!empty($uuids)) {
            $criteria->addFilter(new EqualsAnyFilter('id', $uuids));
        } else {
            $criteria->addFilter(new EqualsAnyFilter('productNumber', $skus));
        }

        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('prices');

        /** @var ProductCollection $products */
        $products = $this->salesChannelProductRepository->search($criteria, $salesChannelContext)->getEntities();

        // Sort products to match the configured order of IDs or SKUs
        $idOrder = array_flip($idList);
        $products->sort(function ($a, $b) use ($idOrder) {
            $posA = $idOrder[$a->getId()] ?? $idOrder[$a->getProductNumber()] ?? 9999;
            $posB = $idOrder[$b->getId()] ?? $idOrder[$b->getProductNumber()] ?? 9999;
            return $posA <=> $posB;
        });

        return $products;
    }
}

