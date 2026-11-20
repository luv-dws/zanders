<?php declare(strict_types=1);

namespace ZandersTheme\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Framework\Log\Package;

#[Package('checkout')]
class QuantityAdjustmentError extends Error
{
    private const KEY = 'zanders-quantity-adjustment';

    public function __construct(
        protected readonly string $productId,
        protected readonly string $productName,
        string $reason
    ) {
        parent::__construct($reason);
    }

    public function getId(): string
    {
        return \sprintf('%s-%s', self::KEY, $this->productId);
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function blockResubmit(): bool
    {
        return true;
    }

    public function isPersistent(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        return [
            'name' => $this->productName,
            'productId' => $this->productId,
        ];
    }
}
