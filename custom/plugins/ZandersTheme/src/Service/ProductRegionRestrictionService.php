<?php declare(strict_types=1);

namespace ZandersTheme\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[Package('checkout')]
class ProductRegionRestrictionService
{
    public const FIELD_ENABLED = 'product_region_restriction_enabled';
    public const FIELD_RESTRICTED_STATES = 'product_restricted_states';

    // Fallback field keys for compatibility
    private const FALLBACK_FIELD_ENABLED = ['custom_region_restriction_enabled', 'region_restriction_enabled'];
    private const FALLBACK_FIELD_STATES = ['custom_restricted_states', 'restricted_states'];

    /**
     * @var array<string, array{enabled: bool, states: list<string>}>
     */
    private array $productDataCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?EntityRepository $productRepository = null
    ) {
    }

    /**
     * Checks whether a product is restricted for the customer's applicable shipping destination state.
     */
    public function isProductRestricted(ProductEntity|string $product, SalesChannelContext $context): bool
    {
        $restrictionData = $this->getProductRestrictionData($product, $context);

        if (!$restrictionData['enabled']) {
            return false;
        }

        $restrictedStates = $restrictionData['states'];

        if (empty($restrictedStates)) {
            return false;
        }

        $destination = $this->getShippingDestination($context);
        $destStateId = $destination['stateId'];

        // Fallback policy: if no shipping state is resolved, allow purchasing to avoid blocking browsing guests
        if ($destStateId === null) {
            return false;
        }

        // State-specific restriction check:
        // Returns true if customer's shipping state matches any of the product's restricted states
        return \in_array($destStateId, $restrictedStates, true);
    }

    /**
     * Retrieves the applicable customer shipping destination with clearly defined priority:
     * 1. Active shipping address of logged-in customer (preferring shipping over billing address).
     * 2. ShippingLocation in SalesChannelContext (for guests, active cart sessions, etc.).
     *
     * @return array{countryId: ?string, stateId: ?string}
     */
    public function getShippingDestination(SalesChannelContext $context): array
    {
        $customer = $context->getCustomer();
        $countryId = null;
        $stateId = null;

        // 1. Logged-in customer: Prefer active shipping address, fallback to default shipping address
        if ($customer !== null) {
            $shippingAddress = $customer->getActiveShippingAddress();

            if (($shippingAddress === null || $shippingAddress->getCountryStateId() === null) && $customer->getDefaultShippingAddress() !== null) {
                $shippingAddress = $customer->getDefaultShippingAddress();
            }

            if ($shippingAddress !== null) {
                $countryId = $shippingAddress->getCountryId();
                $stateId = $shippingAddress->getCountryStateId();
            }
        }

        // 2. Fallback to ShippingLocation in SalesChannelContext (works for guests, active session, etc.)
        if ($stateId === null || $stateId === '') {
            $shippingLocation = $context->getShippingLocation();
            $countryId = $countryId ?? $shippingLocation->getCountry()?->getId();
            $stateId = $shippingLocation->getState()?->getId();
        }

        return [
            'countryId' => $countryId ? strtolower($countryId) : null,
            'stateId' => $stateId ? strtolower($stateId) : null,
        ];
    }

    /**
     * Reads restriction configuration from the product's custom fields.
     *
     * @return array{enabled: bool, states: list<string>}
     */
    public function getProductRestrictionData(ProductEntity|string $product, ?SalesChannelContext $context = null): array
    {
        $productId = $product instanceof ProductEntity ? $product->getId() : $product;
        $productIdLower = strtolower($productId);

        if (isset($this->productDataCache[$productIdLower])) {
            return $this->productDataCache[$productIdLower];
        }

        $customFields = null;
        $parentId = null;

        if ($product instanceof ProductEntity) {
            $customFields = $product->getCustomFields() ?? [];
            $parentId = $product->getParentId();
        }

        // If string ID or custom fields not loaded, query from DB
        if ($customFields === null || empty($customFields)) {
            $row = $this->loadProductRow($productIdLower);
            if ($row !== null) {
                $customFields = $row['customFields'];
                $parentId = $row['parentId'];
            } else {
                $customFields = [];
            }
        }

        $enabled = $this->extractBool($customFields, self::FIELD_ENABLED, self::FALLBACK_FIELD_ENABLED);
        $states = $this->extractIdList($customFields, self::FIELD_RESTRICTED_STATES, self::FALLBACK_FIELD_STATES);

        // Check variant inheritance if not configured on child
        if (!$enabled && empty($states) && $parentId !== null) {
            $parentRow = $this->loadProductRow(strtolower($parentId));
            if ($parentRow !== null && !empty($parentRow['customFields'])) {
                $parentFields = $parentRow['customFields'];
                $enabled = $this->extractBool($parentFields, self::FIELD_ENABLED, self::FALLBACK_FIELD_ENABLED);
                $states = $this->extractIdList($parentFields, self::FIELD_RESTRICTED_STATES, self::FALLBACK_FIELD_STATES);
            }
        }

        $data = [
            'enabled' => $enabled,
            'states' => $states,
        ];

        $this->productDataCache[$productIdLower] = $data;

        return $data;
    }

    /**
     * Clear memory cache (e.g. for testing or after data updates).
     */
    public function resetCache(): void
    {
        $this->productDataCache = [];
    }

    /**
     * @return array{customFields: array<string, mixed>, parentId: ?string}|null
     */
    private function loadProductRow(string $productIdLower): ?array
    {
        if (!Uuid::isValid($productIdLower)) {
            return null;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT pt.custom_fields, LOWER(HEX(p.parent_id)) as parent_id
                 FROM product p
                 LEFT JOIN product_translation pt ON p.id = pt.product_id
                 WHERE p.id = :id
                 ORDER BY (pt.custom_fields IS NOT NULL AND pt.custom_fields != \'[]\' AND pt.custom_fields != \'{}\') DESC
                 LIMIT 1',
                ['id' => Uuid::fromHexToBytes($productIdLower)]
            );

            if ($row === false) {
                return null;
            }

            $customFields = [];
            if (!empty($row['custom_fields'])) {
                $decoded = json_decode((string) $row['custom_fields'], true);
                if (\is_array($decoded)) {
                    $customFields = $decoded;
                }
            }

            return [
                'customFields' => $customFields,
                'parentId' => $row['parent_id'] ?: null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $customFields
     * @param list<string> $fallbacks
     */
    private function extractBool(array $customFields, string $primaryKey, array $fallbacks): bool
    {
        if (\array_key_exists($primaryKey, $customFields)) {
            return (bool) $customFields[$primaryKey];
        }

        foreach ($fallbacks as $fallbackKey) {
            if (\array_key_exists($fallbackKey, $customFields)) {
                return (bool) $customFields[$fallbackKey];
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $customFields
     * @param list<string> $fallbacks
     * @return list<string>
     */
    private function extractIdList(array $customFields, string $primaryKey, array $fallbacks): array
    {
        $value = $customFields[$primaryKey] ?? null;

        if ($value === null) {
            foreach ($fallbacks as $fallbackKey) {
                if (isset($customFields[$fallbackKey])) {
                    $value = $customFields[$fallbackKey];
                    break;
                }
            }
        }

        return $this->normalizeIdList($value);
    }

    /**
     * Normalizes various storage formats (arrays of UUIDs, JSON strings, comma-separated strings) into a clean list of lowercase UUIDs.
     *
     * @return list<string>
     */
    private function normalizeIdList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (\is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
                $decoded = json_decode($trimmed, true);
                if (\is_array($decoded)) {
                    $value = $decoded;
                }
            } else {
                $value = array_map('trim', explode(',', $trimmed));
            }
        }

        if (!\is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                continue;
            }

            $id = strtolower(trim($item));
            if (Uuid::isValid($id)) {
                $normalized[] = $id;
            }
        }

        return array_values(array_unique($normalized));
    }
}
