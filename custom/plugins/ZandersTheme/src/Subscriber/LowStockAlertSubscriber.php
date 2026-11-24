<?php declare(strict_types=1);

namespace ZandersTheme\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use ZandersTheme\Migration\Migration1795502665LowStockMailTemplate;

class LowStockAlertSubscriber implements EventSubscriberInterface
{
    private const CONFIG_PREFIX = 'ZandersTheme.config.';

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     * @param EntityRepository<MailTemplateCollection> $mailTemplateRepository
     */
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly AbstractMailService $mailService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelId();
        $threshold = (int) $this->systemConfigService->get(
            self::CONFIG_PREFIX . 'lowStockThreshold',
            $salesChannelId
        );

        if ($threshold <= 0) {
            return;
        }

        $order = $event->getOrder();
        $lineItems = $order->getNestedLineItems();
        if ($lineItems === null) {
            return;
        }

        $productIds = [];
        foreach ($lineItems as $lineItem) {
            if ($lineItem instanceof OrderLineItemEntity && $lineItem->getProductId() !== null) {
                $productIds[$lineItem->getProductId()] = true;
            }
        }

        if ($productIds === []) {
            return;
        }

        $context = $event->getContext();
        $criteria = new Criteria(array_keys($productIds));
        $criteria->addAssociation('translations');

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        $recipient = $this->resolveRecipient($salesChannelId);
        if ($recipient === null) {
            $this->logger->warning('Low stock alert skipped: no recipient email configured.');
            return;
        }

        foreach ($products as $product) {
            if ($product->getAvailableStock() < $threshold) {
                $this->sendAlertEmail($product, $threshold, $recipient, $salesChannelId, $context);
            }
        }
    }

    private function resolveRecipient(?string $salesChannelId): ?string
    {
        $configured = $this->systemConfigService->get(self::CONFIG_PREFIX . 'recipientEmail', $salesChannelId);
        if (\is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $fallback = $this->systemConfigService->get('core.basicInformation.email', $salesChannelId);

        return (\is_string($fallback) && trim($fallback) !== '') ? trim($fallback) : null;
    }

    private function sendAlertEmail(
        ProductEntity $product,
        int $threshold,
        string $recipient,
        ?string $salesChannelId,
        Context $context,
    ): void {
        $mailTemplate = $this->fetchMailTemplate($context);
        if ($mailTemplate === null) {
            $this->logger->error('Low stock mail template not found.');
            return;
        }

        $senderEmail = (string) ($this->systemConfigService->get('core.basicInformation.email', $salesChannelId) ?? $recipient);
        $shopName = (string) ($this->systemConfigService->get('core.basicInformation.shopName', $salesChannelId) ?? 'Shop');

        $data = [
            'recipients' => [$recipient => $recipient],
            'senderMail' => $senderEmail,
            'senderEmail' => $senderEmail,
            'senderName' => $mailTemplate->getTranslation('senderName') ?? $shopName,
            'templateId' => $mailTemplate->getId(),
            'subject' => $mailTemplate->getTranslation('subject') ?? 'Low stock alert',
            'contentHtml' => $mailTemplate->getTranslation('contentHtml') ?? '',
            'contentPlain' => $mailTemplate->getTranslation('contentPlain') ?? '',
            'salesChannelId' => $salesChannelId,
        ];

        try {
            $this->mailService->send(
                $data,
                $context,
                [
                    'product' => $product,
                    'threshold' => $threshold,
                    'salesChannel' => ['name' => $shopName],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send low stock alert email', [
                'productId' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fetchMailTemplate(Context $context): ?MailTemplateEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', Migration1795502665LowStockMailTemplate::TECHNICAL_NAME));
        $criteria->setLimit(1);

        return $this->mailTemplateRepository->search($criteria, $context)->getEntities()->first();
    }
}
