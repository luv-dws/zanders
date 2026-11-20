<?php declare(strict_types=1);

namespace ZandersTheme\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use ZandersTheme\Service\ProductFinderService;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ProductFinderController extends StorefrontController
{
    public function __construct(
        private readonly ProductFinderService $finderService
    ) {
    }

    /**
     * Dedicated Product Finder search landing page.
     */
    #[Route(
        path: '/finder',
        name: 'frontend.zanders.finder',
        options: ['seo' => true],
        defaults: ['_noStore' => false],
        methods: ['GET']
    )]
    public function index(Request $request, SalesChannelContext $context): Response
    {
        $options = $this->finderService->getFilterOptions($context);
        $result = $this->finderService->search($request, $context);

        $activeFilters = [
            'make' => (string) ($request->get('make') ?: $request->get('manufacturer', '')),
            'caliber' => (string) ($request->get('caliber') ?: $request->get('attribute', '')),
            'gauge' => (string) $request->get('gauge', ''),
            'stockMaterial' => (string) $request->get('stockMaterial', ''),
            'frameMaterial' => (string) $request->get('frameMaterial', ''),
            'typeAction' => (string) ($request->get('typeAction') ?: $request->get('action', '')),
            'typeSights' => (string) ($request->get('typeSights') ?: $request->get('sights', '')),
            'model' => (string) $request->get('model', ''),
            'year' => (string) $request->get('year', ''),
            'type' => (string) $request->get('type', ''),
            'inStock' => $request->query->getBoolean('inStock'),
            'search' => (string) $request->get('search', ''),
            'minPrice' => $request->get('minPrice', ''),
            'maxPrice' => $request->get('maxPrice', ''),
            'order' => (string) $request->get('order', 'name-asc'),
            'p' => max(1, (int) $request->get('p', 1)),
            'limit' => max(1, min(100, (int) $request->get('limit', ProductFinderService::DEFAULT_LIMIT))),
        ];

        $hasActiveFilters = !empty($activeFilters['make'])
            || !empty($activeFilters['caliber'])
            || !empty($activeFilters['gauge'])
            || !empty($activeFilters['stockMaterial'])
            || !empty($activeFilters['frameMaterial'])
            || !empty($activeFilters['typeAction'])
            || !empty($activeFilters['typeSights'])
            || !empty($activeFilters['model'])
            || !empty($activeFilters['year'])
            || !empty($activeFilters['type'])
            || !empty($activeFilters['search'])
            || $activeFilters['inStock']
            || !empty($activeFilters['minPrice'])
            || !empty($activeFilters['maxPrice']);

        $total = $result->getTotal();
        $pages = (int) ceil($total / $activeFilters['limit']);

        return $this->renderStorefront('@Storefront/storefront/page/finder/index.html.twig', [
            'products' => $result->getEntities(),
            'total' => $total,
            'page' => $activeFilters['p'],
            'pages' => $pages,
            'limit' => $activeFilters['limit'],
            'filterOptions' => $options,
            'activeFilters' => $activeFilters,
            'hasActiveFilters' => $hasActiveFilters,
        ]);
    }

    /**
     * Handles search submissions from AJAX finder widgets and storefront forms.
     */
    #[Route(
        path: '/finder/search',
        name: 'frontend.zanders.finder.search',
        defaults: ['_noStore' => true],
        methods: ['GET', 'POST']
    )]
    public function search(Request $request, SalesChannelContext $context): Response
    {
        try {
            $result = $this->finderService->search($request, $context);
            $total = $result->getTotal();
        } catch (\Throwable $e) {
            $total = 0;
            $result = null;
        }

        // AJAX response for live finder widgets (matches reference image "Matching Items: X")
        if ($request->isXmlHttpRequest() || $request->get('format') === 'json') {
            $products = [];
            if ($result !== null) {
                foreach ($result->getEntities() as $product) {
                    $cover = $product->getCover();
                    $coverMedia = $cover ? $cover->getMedia() : null;

                    $price = 0.0;
                    try {
                        $price = $product->getCalculatedPrice()?->getUnitPrice() ?? 0.0;
                    } catch (\Throwable) {
                        $price = 0.0;
                    }

                    $products[] = [
                        'id' => $product->getId(),
                        'name' => $product->getTranslation('name') ?? $product->getName(),
                        'productNumber' => $product->getProductNumber(),
                        'price' => $price,
                        'available' => $product->getAvailable(),
                        'stock' => $product->getAvailableStock() ?? $product->getStock() ?? 0,
                        'coverUrl' => $coverMedia ? $coverMedia->getUrl() : null,
                        'manufacturer' => $product->getManufacturer() ? ($product->getManufacturer()->getTranslation('name') ?? $product->getManufacturer()->getName()) : null,
                        'url' => $this->generateUrl('frontend.detail.page', ['productId' => $product->getId()]),
                    ];
                }
            }

            return new JsonResponse([
                'success' => true,
                'total' => $total,
                'count' => count($products),
                'products' => $products,
            ]);
        }

        // Standard submission: forward or redirect to /finder with query parameters
        $params = $request->query->all();
        if ($request->isMethod('POST')) {
            $params = array_merge($params, $request->request->all());
        }

        return $this->redirectToRoute('frontend.zanders.finder', $params);
    }

    /**
     * Returns dynamic filter options for asynchronous dropdowns.
     */
    #[Route(
        path: '/finder/options',
        name: 'frontend.zanders.finder.options',
        defaults: ['_noStore' => false],
        methods: ['GET']
    )]
    public function options(Request $request, SalesChannelContext $context): JsonResponse
    {
        $options = $this->finderService->getFilterOptions($context);

        return new JsonResponse($options);
    }
}
