<?php declare(strict_types=1);

namespace ZandersTheme\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class QuantityAdjustmentService
{
    public const CONFIG_RULES = 'ZandersTheme.config.quantityAdjustmentRules';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $productRepository
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
