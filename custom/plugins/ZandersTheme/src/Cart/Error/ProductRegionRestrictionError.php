<?php declare(strict_types=1);

namespace ZandersTheme\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Framework\Log\Package;

#[Package('checkout')]
class ProductRegionRestrictionError extends Error
{
    private const KEY = 'product-region-restricted';

    public function __construct(
        protected readonly string $productId,
        protected readonly string $productName
    ) {
        $this->message = \sprintf(
            'This product is not available in your shipping region.'
        );

        parent::__construct($this->message);
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

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return [
            'name' => $this->productName,
            'productId' => $this->productId,
        ];
    }
}
