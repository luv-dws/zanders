<?php declare(strict_types=1);

namespace ZandersTheme\Subscriber;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use ZandersTheme\Core\Content\StockNotification\StockNotificationCollection;
use ZandersTheme\Core\Content\StockNotification\StockNotificationEntity;
use ZandersTheme\Migration\Migration1795600100StockNotificationMailTemplate;

class StockRestockSubscriber implements EventSubscriberInterface
{
    private const CONFIG_PREFIX = 'ZandersTheme.config.';

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     * @param EntityRepository<StockNotificationCollection> $stockNotificationRepository
     * @param EntityRepository<MailTemplateCollection> $mailTemplateRepository
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $stockNotificationRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly AbstractMailService $mailService,
        private readonly RouterInterface $router,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly ?AbstractSalesChannelContextFactory $salesChannelContextFactory = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
        ];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        $restockedProductIds = [];

        foreach ($event->getWriteResults() as $writeResult) {
            if ($writeResult->getOperation() === EntityWriteResult::OPERATION_DELETE) {
                continue;
            }

            $changeSet = $writeResult->getChangeSet();
            $payload = $writeResult->getPayload();

            $primaryKey = $writeResult->getPrimaryKey();
            $productId = \is_array($primaryKey) ? ($primaryKey['id'] ?? null) : $primaryKey;

            if (!\is_string($productId)) {
                continue;
            }

            // Check if available_stock transitioned from <= 0 to > 0
            if ($changeSet !== null && $changeSet->hasChanged('availableStock')) {
                $before = $changeSet->getBefore('availableStock');
                $after = $changeSet->getAfter('availableStock');

                if ($before !== null && (int) $before <= 0 && (int) $after > 0) {
                    $restockedProductIds[$productId] = true;
                }
                continue;
            } elseif ($changeSet !== null && $changeSet->hasChanged('available_stock')) {
                $before = $changeSet->getBefore('available_stock');
                $after = $changeSet->getAfter('available_stock');

                if ($before !== null && (int) $before <= 0 && (int) $after > 0) {
                    $restockedProductIds[$productId] = true;
                }
                continue;
            }

            // Fallback for when changeset is not available or availableStock is written directly in payload
            if (array_key_exists('availableStock', $payload) || array_key_exists('available_stock', $payload)) {
                $stockVal = (int) ($payload['availableStock'] ?? $payload['available_stock']);
                if ($stockVal > 0) {
                    $restockedProductIds[$productId] = true;
                }
            } elseif (array_key_exists('stock', $payload)) {
                $stockVal = (int) $payload['stock'];
                if ($stockVal > 0) {
                    $restockedProductIds[$productId] = true;
                }
            }
        }

        if (empty($restockedProductIds)) {
            return;
        }

        $productIds = array_keys($restockedProductIds);

        // Fetch products to verify availableStock > 0
        $productCriteria = new Criteria($productIds);
        $productCriteria->addAssociation('cover.media');
        $productCriteria->addAssociation('translations');

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($productCriteria, $context)->getEntities();

        $activeRestockedProducts = [];
        foreach ($products as $product) {
            if ($product->getAvailableStock() !== null && $product->getAvailableStock() > 0) {
                $activeRestockedProducts[$product->getId()] = $product;
            }
        }

        if (empty($activeRestockedProducts)) {
            return;
        }

        // Query pending subscriptions for these restocked products
        $notificationCriteria = new Criteria();
        $notificationCriteria->addFilter(new EqualsAnyFilter('productId', array_keys($activeRestockedProducts)));
        $notificationCriteria->addFilter(new EqualsFilter('notifiedAt', null));

        /** @var StockNotificationCollection $notifications */
        $notifications = $this->stockNotificationRepository->search($notificationCriteria, $context)->getEntities();

        if ($notifications->count() === 0) {
            return;
        }

        $mailTemplate = $this->fetchMailTemplate($context);
        if ($mailTemplate === null) {
            $this->logger->error('Back-in-stock mail template not found for notification dispatch.');
            return;
        }

        $now = new \DateTimeImmutable();

        foreach ($notifications as $notification) {
            $salesChannelId = $notification->getSalesChannelId();

            // Check if feature is enabled for this sales channel
            $isEnabled = (bool) $this->systemConfigService->get(self::CONFIG_PREFIX . 'enableNotifyMe', $salesChannelId);
            if (!$isEnabled) {
                continue;
            }

            $product = $activeRestockedProducts[$notification->getProductId()] ?? null;
            if ($product === null) {
                continue;
            }

            try {
                $salesChannel = $this->fetchSalesChannel($salesChannelId, $context);
                $productUrl = $this->resolveProductUrl($product, $salesChannelId);

                $senderEmail = $this->resolveSenderEmail($salesChannelId);
                $shopName = (string) ($this->systemConfigService->get('core.basicInformation.shopName', $salesChannelId) ?? ($salesChannel?->getName() ?? 'Shop'));

                $salesChannelContext = $this->salesChannelContextFactory?->create(Uuid::randomHex(), $salesChannelId);
                $mailContext = $salesChannelContext?->getContext() ?? $context;

                $data = [
                    'recipients' => [$notification->getEmail() => $notification->getEmail()],
                    'senderMail' => $senderEmail,
                    'senderEmail' => $senderEmail,
                    'senderName' => $mailTemplate->getTranslation('senderName') ?? $shopName,
                    'templateId' => $mailTemplate->getId(),
                    'subject' => $mailTemplate->getTranslation('subject') ?? 'Back in stock',
                    'contentHtml' => $mailTemplate->getTranslation('contentHtml') ?? '',
                    'contentPlain' => $mailTemplate->getTranslation('contentPlain') ?? '',
                    'salesChannelId' => $salesChannelId,
                ];

                $templateData = [
                    'product' => $product,
                    'productUrl' => $productUrl,
                    'salesChannel' => [
                        'name' => $shopName,
                        'id' => $salesChannelId,
                    ],
                ];

                if ($salesChannelContext !== null) {
                    $templateData['salesChannelContext'] = $salesChannelContext;
                }

                $this->mailService->send($data, $mailContext, $templateData);

                // Update notifiedAt with current timestamp so subscribers are only notified once per restock cycle
                $this->stockNotificationRepository->update([
                    [
                        'id' => $notification->getId(),
                        'notifiedAt' => $now,
                    ],
                ], $context);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send back-in-stock notification email', [
                    'notificationId' => $notification->getId(),
                    'productId' => $product->getId(),
                    'email' => $notification->getEmail(),
                    'salesChannelId' => $salesChannelId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveProductUrl(ProductEntity $product, string $salesChannelId): string
    {
        $domainUrl = $this->resolveSalesChannelDomain($salesChannelId);

        // Try SEO URL from seo_url table
        $seoPath = $this->connection->fetchOne(
            'SELECT `seo_path_info` FROM `seo_url`
             WHERE `foreign_key` = :productId
               AND (`sales_channel_id` = :salesChannelId OR `sales_channel_id` IS NULL)
               AND `route_name` = :routeName
               AND `is_canonical` = 1
               AND `is_deleted` = 0
             ORDER BY `sales_channel_id` DESC
             LIMIT 1',
            [
                'productId' => Uuid::fromHexToBytes($product->getId()),
                'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
                'routeName' => 'frontend.detail.page',
            ]
        );

        if (\is_string($seoPath) && trim($seoPath) !== '') {
            return rtrim($domainUrl, '/') . '/' . ltrim($seoPath, '/');
        }

        // Fallback to route generator
        try {
            $path = $this->router->generate(
                'frontend.detail.page',
                ['productId' => $product->getId()],
                UrlGeneratorInterface::ABSOLUTE_PATH
            );

            return rtrim($domainUrl, '/') . '/' . ltrim($path, '/');
        } catch (\Throwable) {
            return rtrim($domainUrl, '/') . '/detail/' . $product->getId();
        }
    }

    private function resolveSalesChannelDomain(string $salesChannelId): string
    {
        $domain = $this->connection->fetchOne(
            'SELECT `url` FROM `sales_channel_domain`
             WHERE `sales_channel_id` = :salesChannelId
             ORDER BY `url` LIKE "https%" DESC
             LIMIT 1',
            ['salesChannelId' => Uuid::fromHexToBytes($salesChannelId)]
        );

        if (\is_string($domain) && trim($domain) !== '') {
            return rtrim($domain, '/');
        }

        $appUrl = (string) ($this->systemConfigService->get('core.appUrl') ?? getenv('APP_URL') ?: 'http://localhost');

        return rtrim($appUrl, '/');
    }

    private function resolveSenderEmail(string $salesChannelId): string
    {
        $configured = $this->systemConfigService->get(self::CONFIG_PREFIX . 'notifyMeSenderEmail', $salesChannelId);
        if (\is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $fallback = $this->systemConfigService->get('core.basicInformation.email', $salesChannelId);
        if (\is_string($fallback) && trim($fallback) !== '') {
            return trim($fallback);
        }

        return 'noreply@example.com';
    }

    private function fetchSalesChannel(string $salesChannelId, Context $context): ?SalesChannelEntity
    {
        $criteria = new Criteria([$salesChannelId]);
        return $this->salesChannelRepository->search($criteria, $context)->first();
    }

    private function fetchMailTemplate(Context $context): ?MailTemplateEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', Migration1795600100StockNotificationMailTemplate::TECHNICAL_NAME));
        $criteria->setLimit(1);

        return $this->mailTemplateRepository->search($criteria, $context)->first();
    }
}
