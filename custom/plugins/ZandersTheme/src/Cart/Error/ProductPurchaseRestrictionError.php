<?php declare(strict_types=1);

namespace ZandersTheme\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Framework\Log\Package;

#[Package('checkout')]
class ProductPurchaseRestrictionError extends Error
{
    private const KEY = 'zanders-product-purchase-restricted';

    public function __construct(
        protected readonly string $productId,
        protected readonly string $productName
    ) {
        parent::__construct('Your account is not eligible to purchase this product.');
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
