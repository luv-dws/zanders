<?php declare(strict_types=1);

namespace ZandersTheme\Controller;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class StockNotificationController extends StorefrontController
{
    private const CONFIG_PREFIX = 'ZandersTheme.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $stockNotificationRepository,
        private readonly EntityRepository $productRepository,
    ) {
    }

    #[Route(
        path: '/stock-notification/subscribe',
        name: 'frontend.stock_notification.subscribe',
        defaults: ['_routeScope' => ['storefront']],
        methods: ['POST']
    )]
    public function subscribe(Request $request, RequestDataBag $data, SalesChannelContext $context): Response
    {
        $salesChannelId = $context->getSalesChannelId();
        $isEnabled = (bool) $this->systemConfigService->get(self::CONFIG_PREFIX . 'enableNotifyMe', $salesChannelId);

        if (!$isEnabled) {
            $this->addFlash(self::DANGER, $this->trans('zanders.stockNotification.featureDisabled'));
            return $this->redirectToReferer($request);
        }

        $email = trim((string) $data->get('email', ''));
        $productId = (string) $data->get('productId', '');

        // Validation
        $validator = Validation::createCallable(
            new NotBlank(['message' => 'zanders.stockNotification.emailRequired']),
            new Email(['message' => 'zanders.stockNotification.emailInvalid'])
        );

        $violations = [];
        try {
            $validator($email);
        } catch (\Throwable $e) {
            $violations[] = $e->getMessage();
        }

        if (!Uuid::isValid($productId)) {
            $violations[] = 'zanders.stockNotification.invalidProduct';
        }

        if (!empty($violations)) {
            $this->addFlash(self::DANGER, $this->trans('zanders.stockNotification.invalidInput'));
            return $this->redirectToReferer($request);
        }

        // Check existing product
        $productCriteria = new Criteria([$productId]);
        /** @var ProductEntity|null $product */
        $product = $this->productRepository->search($productCriteria, $context->getContext())->first();

        if ($product === null) {
            $this->addFlash(self::DANGER, $this->trans('zanders.stockNotification.productNotFound'));
            return $this->redirectToReferer($request);
        }

        // Check duplicate active subscription
        $existingCriteria = new Criteria();
        $existingCriteria->addFilter(new EqualsFilter('email', $email));
        $existingCriteria->addFilter(new EqualsFilter('productId', $productId));
        $existingCriteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $existingCriteria->addFilter(new EqualsFilter('notifiedAt', null));

        $existing = $this->stockNotificationRepository->search($existingCriteria, $context->getContext())->first();
        if ($existing !== null) {
            $this->addFlash(self::WARNING, $this->trans('zanders.stockNotification.alreadySubscribed'));
            return $this->redirectToReferer($request);
        }

        // Persist valid subscription
        $this->stockNotificationRepository->create([
            [
                'id' => Uuid::randomHex(),
                'productId' => $productId,
                'productVersionId' => $context->getContext()->getVersionId(),
                'salesChannelId' => $salesChannelId,
                'email' => $email,
                'notifiedAt' => null,
            ],
        ], $context->getContext());

        $this->addFlash(self::SUCCESS, $this->trans('zanders.stockNotification.subscribeSuccess'));
        return $this->redirectToReferer($request);
    }

    private function redirectToReferer(Request $request): Response
    {
        $referer = $request->headers->get('referer');
        if ($referer !== null && $referer !== '') {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('frontend.home.page');
    }
}
