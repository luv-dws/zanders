<?php declare(strict_types=1);

namespace ZandersTheme\Service;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductPurchaseRestrictionService
{
    public const CONFIG_ENABLED = 'ZandersTheme.config.purchaseRestrictionEnabled';
    public const CONFIG_RULE = 'ZandersTheme.config.purchaseRestrictionRule';
    public const CONFIG_GROUPS = 'ZandersTheme.config.purchaseAllowedCustomerGroups';
    public const CONFIG_MINIMUM_ORDER_VALUE = 'ZandersTheme.config.purchaseMinimumOrderValue';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $orderRepository
    ) {
    }

    public function isPurchaseRestricted(SalesChannelContext $context): bool
    {
        $salesChannelId = $context->getSalesChannelId();
        $enabled = $this->systemConfigService->getBool(self::CONFIG_ENABLED, $salesChannelId);
        if (!$enabled) {
            return false;
        }

        $customer = $context->getCustomer();
        if ($customer === null) {
            return true;
        }

        $allowedGroups = $this->systemConfigService->get(self::CONFIG_GROUPS, $salesChannelId);
        if (is_array($allowedGroups) && $allowedGroups !== []) {
            if (!in_array($customer->getGroupId(), $allowedGroups, true)) {
                return true;
            }
        }

        $ruleId = $this->systemConfigService->getString(self::CONFIG_RULE, $salesChannelId);
        if ($ruleId !== '' && !in_array($ruleId, $context->getRuleIds(), true)) {
            return true;
        }

        $minimumOrderValue = (float) ($this->systemConfigService->get(self::CONFIG_MINIMUM_ORDER_VALUE, $salesChannelId) ?? 0);
        if ($minimumOrderValue > 0) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderCustomer.customerId', $customer->getId()));
            $criteria->setLimit(500);
            $criteria->addAssociation('currency');

            $orders = $this->orderRepository->search($criteria, $context->getContext())->getEntities();
            $orderValue = 0.0;
            foreach ($orders as $order) {
                $orderValue += (float) $order->getAmountTotal();
            }

            if ($orderValue < $minimumOrderValue) {
                return true;
            }
        }

        return false;
    }

    public function getRestrictionReason(SalesChannelContext $context): string
    {
        if (!$this->isPurchaseRestricted($context)) {
            return '';
        }

        if ($context->getCustomer() === null) {
            return 'Please log in to purchase this product.';
        }

        return 'Your account is not eligible to purchase this product.';
    }
}
