<?php declare(strict_types=1);

namespace ZandersTheme\Subscriber;

use Shopware\Core\Framework\Adapter\Cache\CacheTagCollector;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductWarningSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $warningLabelRepository,
        private readonly ?CacheTagCollector $cacheTagCollector = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $product = $event->getPage()->getProduct();
        $customFields = $product->getTranslation('customFields') ?? $product->getCustomFields() ?? [];

        $enabled = (bool) ($customFields['zanders_warning_enabled'] ?? $customFields['custom_zanders_warning_enabled'] ?? false);
        $warningLabelId = $customFields['zanders_warning_label_id'] ?? $customFields['custom_zanders_warning_label_id'] ?? null;

        if ($enabled && $warningLabelId) {
            $criteria = new Criteria([$warningLabelId]);
            $warningLabel = $this->warningLabelRepository->search($criteria, $event->getContext())->first();

            if ($warningLabel) {
                $event->getPage()->addExtension('zandersWarningLabel', $warningLabel);

                if ($this->cacheTagCollector !== null) {
                    $this->cacheTagCollector->addTag('zanders-warning-' . $warningLabelId);
                }
            }
        }
    }
}
