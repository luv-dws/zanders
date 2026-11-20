<?php declare(strict_types=1);

namespace ZandersTheme\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

class ProductFinderService
{
    public const DEFAULT_LIMIT = 15;

    /**
     * @param SalesChannelRepository<ProductCollection> $salesChannelProductRepository
     */
    public function __construct(
        private readonly SalesChannelRepository $salesChannelProductRepository,
        private readonly EntityRepository $manufacturerRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly ?EntityRepository $propertyGroupOptionRepository = null,
        private readonly ?SystemConfigService $systemConfigService = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Executes product finder search using custom criteria handling parameters submitted by UI.
     *
     * @return EntitySearchResult<ProductCollection>
     */
    public function search(Request $request, SalesChannelContext $context, int $limit = self::DEFAULT_LIMIT): EntitySearchResult
    {
        $criteria = $this->buildCriteria($request, $context, $limit);

        return $this->salesChannelProductRepository->search($criteria, $context);
    }

    /**
     * Gets the total active catalog product count.
     */
    public function getTotalCatalogCount(SalesChannelContext $context): int
    {
        try {
            $criteria = new Criteria();
            $criteria->setLimit(1);
            $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
            $result = $this->salesChannelProductRepository->search($criteria, $context);
            return $result->getTotal();
        } catch (\Throwable $e) {
            $this->logger?->warning('ProductFinderService: Could not load total count', ['exception' => $e]);
            return 10000;
        }
    }

    /**
     * Builds custom DAL criteria handling Year / Make / Model, Category/Type, and Attribute filter parameters.
     */
    public function buildCriteria(Request $request, SalesChannelContext $context, int $limit = self::DEFAULT_LIMIT): Criteria
    {
        $criteria = new Criteria();
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        $page = max(1, (int) $request->get('p', 1));
        $effectiveLimit = max(1, min(100, (int) $request->get('limit', $limit)));

        $criteria->setLimit($effectiveLimit);
        $criteria->setOffset(($page - 1) * $effectiveLimit);

        // Standard associations for complete card display
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('properties.group');

        // Apply dedicated filter parameters
        $this->applyFinderFilters($criteria, $request, $context);

        // Apply sorting
        $this->applySorting($criteria, (string) $request->get('order', ''));

        return $criteria;
    }

    /**
     * Applies Year / Make / Model / Caliber / Gauge / Material / Action / Sights filters to criteria.
     */
    public function applyFinderFilters(Criteria $criteria, Request $request, ?SalesChannelContext $context = null): void
    {
        // 1. Manufacturer (Make) Filter
        $make = trim((string) ($request->get('make') ?: $request->get('manufacturer', '')));
        if ($make !== '' && $make !== 'all') {
            if (Uuid::isValid($make)) {
                $criteria->addFilter(new EqualsFilter('manufacturerId', $make));
            } else {
                $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                    new EqualsFilter('manufacturer.name', $make),
                    new ContainsFilter('manufacturer.name', $make),
                ]));
            }
        }

        // 2. Caliber Filter
        $caliber = trim((string) ($request->get('caliber') ?: $request->get('attribute', '')));
        if ($caliber !== '' && $caliber !== 'all') {
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new ContainsFilter('name', $caliber),
                new ContainsFilter('properties.name', $caliber),
                new ContainsFilter('description', $caliber),
            ]));
        }

        // 3. Gauge Filter
        $gauge = trim((string) $request->get('gauge', ''));
        if ($gauge !== '' && $gauge !== 'all') {
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new ContainsFilter('name', $gauge),
                new ContainsFilter('properties.name', $gauge),
                new ContainsFilter('description', $gauge),
            ]));
        }

        // 4. Stock Material Filter
        $stockMaterial = trim((string) $request->get('stockMaterial', ''));
        if ($stockMaterial !== '' && $stockMaterial !== 'all') {
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new ContainsFilter('name', $stockMaterial),
                new ContainsFilter('properties.name', $stockMaterial),
                new ContainsFilter('description', $stockMaterial),
            ]));
        }

        // 5. Frame Material Filter
        $frameMaterial = trim((string) $request->get('frameMaterial', ''));
        if ($frameMaterial !== '' && $frameMaterial !== 'all') {
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new ContainsFilter('name', $frameMaterial),
                new ContainsFilter('properties.name', $frameMaterial),
                new ContainsFilter('description', $frameMaterial),
            ]));
        }

        // 6. Action Type Filter
        $typeAction = trim((string) ($request->get('typeAction') ?: $request->get('action', '')));
        if ($typeAction !== '' && $typeAction !== 'all') {
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new ContainsFilter('name', $typeAction),
                new ContainsFilter('properties.name', $typeAction),
                new ContainsFilter('description', $typeAction),
            ]));
        }

        // 7. Sights Type Filter
        $typeSights = trim((string) ($request->get('typeSights') ?: $request->get('sights', '')));
        if ($typeSights !== '' && $typeSights !== 'all') {
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new ContainsFilter('name', $typeSights),
                new ContainsFilter('properties.name', $typeSights),
                new ContainsFilter('description', $typeSights),
            ]));
        }

        // Model Filter (optional keyword filter)
        $model = trim((string) $request->get('model', ''));
        if ($model !== '' && $model !== 'all') {
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new ContainsFilter('name', $model),
                new ContainsFilter('productNumber', $model),
                new ContainsFilter('description', $model),
                new ContainsFilter('properties.name', $model),
            ]));
        }

        // Year Filter (optional keyword filter)
        $year = trim((string) $request->get('year', ''));
        if ($year !== '' && $year !== 'all') {
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new ContainsFilter('name', $year),
                new ContainsFilter('properties.name', $year),
                new ContainsFilter('description', $year),
            ]));
        }

        // Type / Category Filter
        $type = trim((string) ($request->get('type') ?: $request->get('category', '')));
        if ($type !== '' && $type !== 'all') {
            if (Uuid::isValid($type)) {
                $criteria->addFilter(new EqualsAnyFilter('categoryTree', [$type]));
            } else {
                $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                    new ContainsFilter('categories.name', $type),
                    new ContainsFilter('name', $type),
                ]));
            }
        }

        // Generic search query
        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new ContainsFilter('name', $search),
                new ContainsFilter('productNumber', $search),
                new ContainsFilter('ean', $search),
                new ContainsFilter('description', $search),
            ]));
        }

        // In Stock Filter
        $inStockOnly = $request->query->getBoolean('inStock');
        $hideOutOfStock = false;
        if ($this->systemConfigService !== null && $context !== null) {
            $hideOutOfStock = $this->systemConfigService->getBool('ZandersTheme.config.hideOutOfStockProducts', $context->getSalesChannelId());
        }

        if ($inStockOnly || $hideOutOfStock) {
            $criteria->addFilter(new RangeFilter('availableStock', [RangeFilter::GT => 0]));
        }

        // Price Filters
        $minPrice = $request->get('minPrice') !== null && $request->get('minPrice') !== '' ? (float) $request->get('minPrice') : null;
        $maxPrice = $request->get('maxPrice') !== null && $request->get('maxPrice') !== '' ? (float) $request->get('maxPrice') : null;

        if ($minPrice !== null && $minPrice > 0) {
            $criteria->addFilter(new RangeFilter('cheapestPrice', [RangeFilter::GTE => $minPrice]));
        }
        if ($maxPrice !== null && $maxPrice > 0) {
            $criteria->addFilter(new RangeFilter('cheapestPrice', [RangeFilter::LTE => $maxPrice]));
        }
    }

    /**
     * Applies sorting to criteria.
     */
    private function applySorting(Criteria $criteria, string $order): void
    {
        switch ($order) {
            case 'price-desc':
                $criteria->addSorting(new FieldSorting('cheapestPrice', FieldSorting::DESCENDING));
                break;
            case 'price-asc':
                $criteria->addSorting(new FieldSorting('cheapestPrice', FieldSorting::ASCENDING));
                break;
            case 'name-asc':
                $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
                break;
            case 'name-desc':
                $criteria->addSorting(new FieldSorting('name', FieldSorting::DESCENDING));
                break;
            case 'topseller':
                $criteria->addSorting(new FieldSorting('sales', FieldSorting::DESCENDING));
                break;
            default:
                $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
                break;
        }
    }

    /**
     * Retrieves available options for all 7 dropdowns shown in the Product Finder reference drawer.
     */
    public function getFilterOptions(SalesChannelContext $context): array
    {
        $contextDAL = $context->getContext();

        // 1. Manufacturers
        $manufacturers = [];
        try {
            $mCriteria = new Criteria();
            $mCriteria->setLimit(150);
            $mCriteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
            $mEntities = $this->manufacturerRepository->search($mCriteria, $contextDAL)->getEntities();
            foreach ($mEntities as $manufacturer) {
                $manufacturers[] = [
                    'id' => $manufacturer->getId(),
                    'name' => $manufacturer->getTranslation('name') ?? $manufacturer->getName() ?? '',
                ];
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('ProductFinderService: Could not load manufacturers', ['exception' => $e]);
        }

        if (empty($manufacturers)) {
            $defaultMakes = [
                'Glock', 'Smith & Wesson', 'Ruger', 'Winchester', 'Remington', 'Umarex',
                'Henry Repeating Arms', 'Colt', 'Sig Sauer', 'Beretta', 'Benelli', 'Taurus',
                'Mossberg', 'Browning', 'Springfield Armory', 'CZ-USA', 'Walther', 'Kimber',
                'Savage Arms', 'Marlin', 'Kel-Tec', 'Canik', 'Shadow Systems', 'CVA', 'Black Rain Ordnance',
            ];
            foreach ($defaultMakes as $make) {
                $manufacturers[] = ['id' => $make, 'name' => $make];
            }
        }

        // 2. Calibers
        $calibers = [
            '9mm', '9mm Luger', '.22 LR', '.45 ACP', '.380 ACP', '.40 S&W', '.357 Magnum',
            '.38 Special', '5.56x45mm NATO', '.223 Remington', '.308 Winchester', '7.62x39mm',
            '6.5 Creedmoor', '.300 AAC Blackout', '.30-06 Springfield', '.50 Caliber / Ball',
            '10mm Auto', '.350 Legend', '.450 Bushmaster', '6.5 PRC', '.300 Winchester Magnum',
            '.17 HMR', '.22 WMR', '5.7x28mm', '.44 Magnum', '.45 Colt', '6mm Creedmoor',
        ];
        $dbCalibers = $this->loadPropertyOptionNames('Caliber', $contextDAL);
        if (!empty($dbCalibers)) {
            $calibers = array_values(array_unique(array_merge($dbCalibers, $calibers)));
        }

        // 3. Gauges
        $gauges = [
            '12 Gauge', '20 Gauge', '28 Gauge', '16 Gauge', '10 Gauge', '.410 Bore', '14 Gauge',
        ];

        // 4. Stock Materials
        $stockMaterials = [
            'Synthetic', 'Walnut', 'Wood', 'Polymer', 'Laminate', 'Carbon Fiber',
            'Aluminum', 'Composite', 'Hardwood', 'Camo Synthetic', 'Birch', 'Steel / Folding',
            'Rubber', 'G10',
        ];
        $dbStockMaterials = $this->loadPropertyOptionNames('Stock Material', $contextDAL);
        if (!empty($dbStockMaterials)) {
            $stockMaterials = array_values(array_unique(array_merge($dbStockMaterials, $stockMaterials)));
        }

        // 5. Frame Materials
        $frameMaterials = [
            'Steel', 'Aluminum', 'Polymer', 'Stainless Steel', 'Alloy', 'Carbon Steel',
            'Titanium', 'Scandium Alloy',
        ];

        // 6. Action Types
        $typeActions = [
            'Semi-Automatic', 'Bolt Action', 'Lever Action', 'Pump Action', 'Revolver',
            'Single Shot', 'Break Open', 'Over / Under', 'Side-by-Side',
        ];
        $dbTypeActions = $this->loadPropertyOptionNames('Type Action', $contextDAL);
        if (!empty($dbTypeActions)) {
            $typeActions = array_values(array_unique(array_merge($dbTypeActions, $typeActions)));
        }

        // 7. Types of Sights
        $typeSights = [
            'Fixed Sights', 'Adjustable Sights', 'Night Sights', 'Fiber Optic',
            'Optics Ready', 'Picatinny Rail', '3-Dot Sights',
        ];
        $dbTypeSights = $this->loadPropertyOptionNames('Sights', $contextDAL);
        if (!empty($dbTypeSights)) {
            $typeSights = array_values(array_unique(array_merge($dbTypeSights, $typeSights)));
        }

        $totalCount = $this->getTotalCatalogCount($context);

        return [
            'manufacturers' => $manufacturers,
            'calibers' => $calibers,
            'gauges' => $gauges,
            'stockMaterials' => $stockMaterials,
            'frameMaterials' => $frameMaterials,
            'typeActions' => $typeActions,
            'typeSights' => $typeSights,
            'totalCount' => $totalCount,
        ];
    }

    /**
     * Helper to load option names from a Property Group by name.
     */
    private function loadPropertyOptionNames(string $groupName, \Shopware\Core\Framework\Context $context): array
    {
        if ($this->propertyGroupOptionRepository === null) {
            return [];
        }

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('group.name', $groupName));
            $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
            $criteria->setLimit(250);

            $options = $this->propertyGroupOptionRepository->search($criteria, $context)->getEntities();
            $names = [];
            foreach ($options as $opt) {
                $n = $opt->getTranslation('name') ?? $opt->getName();
                if (!empty($n) && !in_array($n, $names, true)) {
                    $names[] = $n;
                }
            }
            return $names;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
