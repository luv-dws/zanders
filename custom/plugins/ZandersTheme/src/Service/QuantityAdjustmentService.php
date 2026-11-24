<?php declare(strict_types=1);

namespace ZandersTheme\Service;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class QuantityAdjustmentService
{
    public const CONFIG_RULES = 'ZandersTheme.config.quantityAdjustmentRules';
    public const PRODUCT_FIELD = 'zanders_quantity_adjustment_rules';
    public const CATEGORY_FIELD = 'zanders_category_quantity_adjustment_rules';
    private const PRODUCT_INDIVIDUAL_FIELDS = [
        'qtyFrom' => 'zanders_qty_from',
        'qtyTo' => 'zanders_qty_to',
        'step' => 'zanders_qty_step',
        'startFrom' => 'zanders_qty_start_from',
        'startTo' => 'zanders_qty_start_to',
        'display' => 'zanders_qty_string',
    ];
    private const CATEGORY_INDIVIDUAL_FIELDS = [
        'qtyFrom' => 'zanders_category_qty_from',
        'qtyTo' => 'zanders_category_qty_to',
        'step' => 'zanders_category_qty_step',
        'startFrom' => 'zanders_category_qty_start_from',
        'startTo' => 'zanders_category_qty_start_to',
        'display' => 'zanders_category_qty_string',
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categoryRepository
    ) {
    }

    /**
     * @return array{from:int,to:int,step:int,display:string,source:string|null}
     */
    public function resolve(ProductEntity|string $product, SalesChannelContext $context, ?int $quantity = null): array
    {
        if (\is_string($product)) {
            $product = $this->productRepository->search(new Criteria([$product]), $context->getContext())->first();
        }

        if (!$product instanceof ProductEntity) {
            return $this->defaultAdjustment();
        }

        $from = max(1, (int) ($product->getMinPurchase() ?: 1));
        $maxPurchase = method_exists($product, 'getCalculatedMaxPurchase')
            ? $product->getCalculatedMaxPurchase()
            : $product->getMaxPurchase();
        $to = max($from, (int) ($maxPurchase ?: 100));
        $step = max(1, (int) ($product->getPurchaseSteps() ?: 1));
        $default = [
            'from' => $from,
            'to' => $to,
            'step' => $step,
            'display' => '',
            'source' => null,
        ];

        $productCustomFields = $product->getCustomFields() ?? [];
        $productRules = $this->decodeIndividualRule($productCustomFields, self::PRODUCT_INDIVIDUAL_FIELDS);
        if ($productRules === []) {
            $productRules = $this->decodeRules($productCustomFields[self::PRODUCT_FIELD] ?? null);
        }
        $matched = $this->findMatchingRule($productRules);
        if ($matched !== null) {
            return $this->applyRule($matched, $default, 'product');
        }

        $categoryIds = $product->getCategoryTree() ?? [];
        if ($categoryIds === [] && $product->getCategories() !== null) {
            $categoryIds = $product->getCategories()->getIds();
        }

        if ($categoryIds !== []) {
            $criteria = new Criteria(array_values(array_unique($categoryIds)));
            $categories = $this->categoryRepository->search($criteria, $context->getContext())->getEntities();

            foreach ($categories as $category) {
                if (!$category instanceof CategoryEntity) {
                    continue;
                }
                $categoryCustomFields = $category->getCustomFields() ?? [];
                $categoryRules = $this->decodeIndividualRule($categoryCustomFields, self::CATEGORY_INDIVIDUAL_FIELDS);
                if ($categoryRules === []) {
                    $categoryRules = $this->decodeRules($categoryCustomFields[self::CATEGORY_FIELD] ?? null);
                }
                $matched = $this->findMatchingRule($categoryRules);
                if ($matched !== null) {
                    return $this->applyRule($matched, $default, 'category');
                }
            }
        }

        $systemValue = $this->systemConfigService->get(self::CONFIG_RULES, $context->getSalesChannelId());
        $matched = $this->findMatchingRule($this->decodeRules($systemValue));
        if ($matched !== null) {
            return $this->applyRule($matched, $default, 'system');
        }

        return $default;
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @return array<string,mixed>|null
     */
    private function findMatchingRule(array $rules): ?array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        foreach ($rules as $rule) {
            $start = trim((string) ($rule['startFrom'] ?? $rule['start_from'] ?? ''));
            $end = trim((string) ($rule['startTo'] ?? $rule['start_to'] ?? ''));

            if ($start !== '' && $today < $start) {
                continue;
            }
            if ($end !== '' && $today > $end) {
                continue;
            }

            $from = (int) ($rule['qtyFrom'] ?? $rule['qty_from'] ?? $rule['from'] ?? 0);
            $to = (int) ($rule['qtyTo'] ?? $rule['qty_to'] ?? $rule['to'] ?? 0);
            $step = (int) ($rule['step'] ?? $rule['stepValue'] ?? $rule['value'] ?? 0);

            if ($from > 0 && $to >= $from && $step > 0) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $rule
     * @param array{from:int,to:int,step:int,display:string,source:string|null} $default
     * @return array{from:int,to:int,step:int,display:string,source:string}
     */
    private function applyRule(array $rule, array $default, string $source): array
    {
        $from = (int) ($rule['qtyFrom'] ?? $rule['qty_from'] ?? $rule['from'] ?? $default['from']);
        $to = (int) ($rule['qtyTo'] ?? $rule['qty_to'] ?? $rule['to'] ?? $default['to']);
        $step = (int) ($rule['step'] ?? $rule['stepValue'] ?? $rule['value'] ?? $default['step']);

        return [
            'from' => max(1, $from),
            'to' => max($from, $to),
            'step' => max(1, $step),
            'display' => (string) ($rule['display'] ?? $rule['string'] ?? ''),
            'source' => $source,
        ];
    }

    /**
     * @return array{from:int,to:int,step:int,display:string,source:string|null}
     */
    private function defaultAdjustment(): array
    {
        return ['from' => 1, 'to' => 100, 'step' => 1, 'display' => '', 'source' => null];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function decodeRules(mixed $value): array
    {
        if (\is_array($value)) {
            return array_is_list($value) ? $value : [$value];
        }
        if (!\is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!\is_array($decoded)) {
            return [];
        }

        return array_is_list($decoded) ? $decoded : [$decoded];
    }

    /**
     * @param array<string,mixed> $customFields
     * @param array<string,string> $fieldNames
     * @return list<array<string,mixed>>
     */
    private function decodeIndividualRule(array $customFields, array $fieldNames): array
    {
        $qtyFrom = (int) ($customFields[$fieldNames['qtyFrom']] ?? 0);
        $qtyTo = (int) ($customFields[$fieldNames['qtyTo']] ?? 0);

        if ($qtyFrom <= 0 || $qtyTo < $qtyFrom) {
            return [];
        }

        return [[
            'qtyFrom' => $qtyFrom,
            'qtyTo' => $qtyTo,
            'step' => (int) ($customFields[$fieldNames['step']] ?? 1),
            'startFrom' => (string) ($customFields[$fieldNames['startFrom']] ?? ''),
            'startTo' => (string) ($customFields[$fieldNames['startTo']] ?? ''),
            'display' => (string) ($customFields[$fieldNames['display']] ?? ''),
        ]];
    }

    public function validateQuantity(ProductEntity|string $product, int $quantity, SalesChannelContext $context): ?string
    {
        $adjustment = $this->resolve($product, $context, $quantity);
        if ($quantity < $adjustment['from']) {
            return \sprintf('The minimum quantity for this product is %d.', $adjustment['from']);
        }
        if ($quantity > $adjustment['to']) {
            return \sprintf('The maximum quantity for this product is %d.', $adjustment['to']);
        }
        if ($adjustment['step'] > 1 && (($quantity - $adjustment['from']) % $adjustment['step']) !== 0) {
            return \sprintf(
                'Quantity must increase by steps of %d starting from %d.',
                $adjustment['step'],
                $adjustment['from']
            );
        }

        return null;
    }
}
