<?php declare(strict_types=1);

namespace ZandersTheme\Subscriber;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\Event\CustomerSetDefaultShippingAddressEvent;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheCookieEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use ZandersTheme\Service\ProductRegionRestrictionService;

class ProductRestrictionStorefrontSubscriber implements EventSubscriberInterface
{
    public const EXTENSION_NAME = 'zandersRestriction';

    public function __construct(
        private readonly ProductRegionRestrictionService $restrictionService,
        private readonly ?AbstractContextSwitchRoute $contextSwitchRoute = null,
        private readonly ?CartService $cartService = null,
        private readonly ?EntityRepository $addressRepository = null
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
            ProductListingResultEvent::class => 'onProductListingResult',
            HttpCacheCookieEvent::class => 'onHttpCacheCookie',
            CustomerSetDefaultShippingAddressEvent::class => 'onDefaultShippingAddressChanged',
        ];
    }

    /**
     * Synchronizes the active session context whenever the customer changes their default shipping address
     * from the user profile Addresses section, ensuring restriction validation and listing pages reflect it immediately.
     */
    public function onDefaultShippingAddressChanged(CustomerSetDefaultShippingAddressEvent $event): void
    {
        $addressId = $event->getAddressId();
        $context = $event->getSalesChannelContext();
        $customer = $event->getCustomer();

        // 1. Synchronize the active session context's shipping address with the new default shipping address
        if ($this->contextSwitchRoute !== null) {
            try {
                $this->contextSwitchRoute->switchContext(
                    new RequestDataBag([SalesChannelContextService::SHIPPING_ADDRESS_ID => $addressId]),
                    $context
                );
            } catch (\Throwable) {
            }
        }

        // 2. Update the in-memory CustomerEntity for the remainder of this request
        if ($this->addressRepository !== null) {
            try {
                $criteria = new Criteria([$addressId]);
                $criteria->addAssociation('countryState');
                $criteria->addAssociation('country');
                $address = $this->addressRepository->search($criteria, $context->getContext())->first();

                if ($address instanceof CustomerAddressEntity) {
                    $customer->setDefaultShippingAddress($address);
                    $customer->setActiveShippingAddress($address);
                }
            } catch (\Throwable) {
            }
        }

        // 3. Recalculate cart to re-evaluate restrictions immediately
        if ($this->cartService !== null) {
            try {
                $cart = $this->cartService->getCart($context->getToken(), $context);
                if ($cart->getLineItems()->count() > 0) {
                    $this->cartService->recalculate($cart, $context);
                }
            } catch (\Throwable) {
            }
        }
    }

    public function onHttpCacheCookie(HttpCacheCookieEvent $event): void
    {
        $context = $event->context;
        $destination = $this->restrictionService->getShippingDestination($context);
        $stateId = $destination['stateId'] ?? 'none';
        $event->add('customer-shipping-state', $stateId);
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $product = $event->getPage()->getProduct();
        $context = $event->getSalesChannelContext();

        $isRestricted = $this->restrictionService->isProductRestricted($product, $context);
        $product->addExtension(self::EXTENSION_NAME, new ArrayEntity([
            'isRestricted' => $isRestricted,
        ]));
    }

    public function onProductListingResult(ProductListingResultEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        foreach ($event->getResult()->getEntities() as $product) {
            $isRestricted = $this->restrictionService->isProductRestricted($product, $context);
            $product->addExtension(self::EXTENSION_NAME, new ArrayEntity([
                'isRestricted' => $isRestricted,
            ]));
        }
    }
}
