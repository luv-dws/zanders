<?php declare(strict_types=1);

namespace ZandersTheme\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\Product\Events\ProductStockAlteredEvent;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class LowStockAlertService
{
    public const CONFIG_KEY_ENABLED = 'ZandersTheme.config.lowStockAlertEnabled';
    public const CONFIG_KEY_THRESHOLD = 'ZandersTheme.config.lowStockThreshold';
    public const CONFIG_KEY_EMAIL = 'ZandersTheme.config.lowStockNotificationEmail';
    public const CONFIG_KEY_SUBJECT = 'ZandersTheme.config.lowStockEmailSubject';

    public const DEFAULT_THRESHOLD = 5;
    public const DEFAULT_SUBJECT = '[Low Stock Alert] Product inventory running low';

    /**
     * Cache product IDs alerted within the current process to prevent duplicate alerts.
     *
     * @var array<string, bool>
     */
    private array $recentlyAlerted = [];

    /**
     * @param EntityRepository $productRepository
     */
    public function __construct(
        private readonly AbstractMailService $mailService,
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handles checkout order placed event to detect stock drops.
     */
    public function handleOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        if (!$this->isEnabled($salesChannelId)) {
            return;
        }

        $order = $event->getOrder();
        $lineItems = $order->getLineItems();

        if ($lineItems === null || $lineItems->count() === 0) {
            return;
        }

        $productIds = [];
        foreach ($lineItems as $lineItem) {
            $type = $lineItem->getType();
            if ($type === null || $type === LineItem::PRODUCT_LINE_ITEM_TYPE || $type === 'product') {
                $productId = $lineItem->getProductId() ?? $lineItem->getReferencedId();
                if ($productId) {
                    $productIds[] = $productId;
                }
            }
        }

        $productIds = array_values(array_unique($productIds));
        if (empty($productIds)) {
            return;
        }

        $this->processLowStockAlert(
            $productIds,
            $event->getContext(),
            $salesChannelId,
            $order
        );
    }

    /**
     * Handles product stock altered event (e.g. backend edits, ERP imports, manual stock updates).
     */
    public function handleStockAltered(ProductStockAlteredEvent $event): void
    {
        if (!$this->isEnabled(null)) {
            return;
        }

        $productIds = $event->getIds();
        if (empty($productIds)) {
            return;
        }

        // Filter out any products already alerted within this process
        $unalertedProductIds = array_values(array_filter(
            $productIds,
            fn(string $id) => !isset($this->recentlyAlerted[$id])
        ));

        if (empty($unalertedProductIds)) {
            return;
        }

        $this->processLowStockAlert(
            $unalertedProductIds,
            $event->getContext(),
            null,
            null
        );
    }

    /**
     * Evaluates product stock levels and triggers email notification if below threshold.
     *
     * @param list<string> $productIds
     */
    public function processLowStockAlert(
        array $productIds,
        Context $context,
        ?string $salesChannelId = null,
        ?OrderEntity $order = null
    ): void {
        try {
            $threshold = $this->getThreshold($salesChannelId);

            $criteria = new Criteria($productIds);
            $criteria->addAssociation('translations');

            $products = $this->productRepository->search($criteria, $context)->getEntities();

            $lowStockProducts = [];

            /** @var ProductEntity $product */
            foreach ($products as $product) {
                $productId = $product->getId();
                $availableStock = $product->getAvailableStock();
                $physicalStock = $product->getStock();

                // Use availableStock if present; fallback to physical stock
                $currentStock = $availableStock ?? $physicalStock;

                if ($currentStock <= $threshold) {
                    $lowStockProducts[] = [
                        'id' => $productId,
                        'name' => $product->getTranslation('name') ?? $product->getName() ?? 'Product (' . $productId . ')',
                        'productNumber' => $product->getProductNumber(),
                        'stock' => $currentStock,
                        'physicalStock' => $physicalStock,
                        'availableStock' => $availableStock,
                    ];

                    $this->recentlyAlerted[$productId] = true;
                }
            }

            if (empty($lowStockProducts)) {
                return;
            }

            $this->sendNotificationEmail(
                $lowStockProducts,
                $threshold,
                $context,
                $salesChannelId,
                $order
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'LowStockAlertService: Failed to process low stock check: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Sends the low stock alert email via MailService.
     *
     * @param list<array<string, mixed>> $lowStockProducts
     */
    private function sendNotificationEmail(
        array $lowStockProducts,
        int $threshold,
        Context $context,
        ?string $salesChannelId,
        ?OrderEntity $order
    ): void {
        $recipients = $this->resolveRecipients($salesChannelId);

        if (empty($recipients)) {
            $this->logger->info(
                'LowStockAlertService: No valid email address configured in ' . self::CONFIG_KEY_EMAIL . '. Low stock email alert skipped.'
            );
            return;
        }

        $shopName = $this->resolveShopName($salesChannelId);
        $subject = $this->resolveSubject($salesChannelId, $shopName);
        $adminUrl = $this->resolveAdminUrl();

        $templateData = [
            'shopName' => $shopName,
            'threshold' => $threshold,
            'lowStockProducts' => $lowStockProducts,
            'productCount' => \count($lowStockProducts),
            'order' => $order,
            'date' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'adminUrl' => $adminUrl,
            'subject' => $subject,
        ];

        $contentHtml = $this->loadTemplateHtml();
        $contentPlain = $this->loadTemplatePlain();

        $mailData = [
            'recipients' => $recipients,
            'senderName' => $shopName,
            'subject' => $subject,
            'contentHtml' => $contentHtml,
            'contentPlain' => $contentPlain,
        ];

        if ($salesChannelId !== null) {
            $mailData['salesChannelId'] = $salesChannelId;
        }

        try {
            $this->mailService->send($mailData, $context, $templateData);

            $this->logger->info(
                \sprintf(
                    'LowStockAlertService: Low stock alert email sent to %s for %d product(s).',
                    implode(', ', array_keys($recipients)),
                    \count($lowStockProducts)
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'LowStockAlertService: Error sending low stock email alert: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    public function isEnabled(?string $salesChannelId): bool
    {
        $enabled = $this->systemConfigService->get(self::CONFIG_KEY_ENABLED, $salesChannelId);

        if ($enabled === null) {
            return true;
        }

        return (bool) $enabled;
    }

    public function getThreshold(?string $salesChannelId): int
    {
        $threshold = $this->systemConfigService->get(self::CONFIG_KEY_THRESHOLD, $salesChannelId);

        if ($threshold === null || $threshold === '') {
            return self::DEFAULT_THRESHOLD;
        }

        return max(0, (int) $threshold);
    }

    /**
     * Resolves only the configured recipient email address(es).
     * Strictly avoids sending to any other email address (no fallback to store email,
     * mailer sender address, or administrator accounts).
     *
     * @return array<string, string> Map of [email => name]
     */
    public function resolveRecipients(?string $salesChannelId): array
    {
        $configuredEmail = $this->systemConfigService->getString(self::CONFIG_KEY_EMAIL, $salesChannelId);
        $configuredEmail = trim($configuredEmail);

        if ($configuredEmail === '') {
            return [];
        }

        // Support single email or comma/semicolon-separated list of emails
        $emailList = array_map('trim', preg_split('/[,;]+/', $configuredEmail) ?: []);
        $recipients = [];

        foreach ($emailList as $email) {
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[$email] = 'Site Administrator';
            }
        }

        return $recipients;
    }

    private function resolveShopName(?string $salesChannelId): string
    {
        $shopName = $this->systemConfigService->getString('core.basicInformation.shopName', $salesChannelId);
        if (!empty(trim($shopName))) {
            return trim($shopName);
        }

        return 'ZANDERS';
    }

    private function resolveSubject(?string $salesChannelId, string $shopName): string
    {
        $subject = $this->systemConfigService->getString(self::CONFIG_KEY_SUBJECT, $salesChannelId);
        if (!empty(trim($subject))) {
            return trim($subject);
        }

        return self::DEFAULT_SUBJECT . ' - ' . $shopName;
    }

    private function resolveAdminUrl(): string
    {
        $appUrl = '';
        if (isset($_SERVER['APP_URL'])) {
            $appUrl = (string) $_SERVER['APP_URL'];
        }

        if (empty($appUrl)) {
            $appUrl = $this->systemConfigService->getString('core.appUrl') ?: '';
        }

        return rtrim($appUrl, '/') . '/admin#/sw/product/index';
    }

    private function loadTemplateHtml(): string
    {
        $templatePath = __DIR__ . '/../Resources/views/email/low-stock-alert.html.twig';
        if (file_exists($templatePath)) {
            $content = file_get_contents($templatePath);
            if ($content !== false && trim($content) !== '') {
                return $content;
            }
        }

        // Fallback HTML template
        return '<p>Hello Administrator,</p><p>The following products are low in stock (Threshold: {{ threshold }}):</p>'
            . '<ul>{% for item in lowStockProducts %}<li>{{ item.name }} (SKU: {{ item.productNumber }}) - Stock: {{ item.stock }}</li>{% endfor %}</ul>';
    }

    private function loadTemplatePlain(): string
    {
        return "Hello Administrator,\n\nThe following products are low in stock (Threshold: {{ threshold }}):\n"
            . "{% for item in lowStockProducts %}* {{ item.name }} (SKU: {{ item.productNumber }}) - Stock: {{ item.stock }}\n{% endfor %}";
    }
}
