<?php

declare(strict_types=1);

namespace Extcode\CartGirosolution\EventListener\Order\Payment;

/*
 * This file is part of the package extcode/cart-girosolution.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Extcode\Cart\Domain\Model\Cart;
use Extcode\Cart\Domain\Model\Order\Item as OrderItem;
use Extcode\Cart\Domain\Model\Order\Transaction;
use Extcode\Cart\Domain\Repository\CartRepository;
use Extcode\Cart\Domain\Repository\Order\PaymentRepository;
use Extcode\Cart\Event\Order\PaymentEvent;
use Extcode\CartGirosolution\Configuration\CredentialLoaderRegistry;
use girosolution\GiroCheckout_SDK\GiroCheckout_SDK_Request;
use InvalidArgumentException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class ProviderRedirect
{
    private array $credentials = [];

    private array $conf = [];

    private readonly int $cartPid;

    private readonly int $orderPid;

    private ?OrderItem $orderItem = null;

    private readonly RouterInterface $pageRouter;

    public function __construct(
        private readonly ConfigurationManager $configurationManager,
        private readonly PersistenceManager $persistenceManager,
        private readonly SiteFinder $siteFinder,
        private readonly CartRepository $cartRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        public readonly CredentialLoaderRegistry $credentialLoaderRegistry
    ) {
        $this->credentials = $this->credentialLoaderRegistry->getCredentials();

        $this->conf = $this->configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_FRAMEWORK,
            'CartGirosolution'
        );
        if (isset($this->conf['credentials'])) {
            unset($this->conf['credentials']);
        }

        $cartConf = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
            'Cart'
        );

        $this->cartPid = (int)$cartConf['settings']['cart']['pid'];
        $this->orderPid = (int)$cartConf['settings']['order']['pid'];

        $this->pageRouter = $this->siteFinder->getSiteByPageId($this->cartPid)->getRouter();
    }

    public function __invoke(PaymentEvent $event): void
    {
        $this->orderItem = $event->getOrderItem();

        $payment = $this->orderItem->getPayment();
        $provider = $payment->getProvider();
        // Payment methods of other providers (e.g. prepayment) have no or another provider
        [$provider, $clearingType] = array_pad(explode('_', (string)$provider), 2, '');

        if ($provider !== 'GIROSOLUTION') {
            return;
        }

        $request = $this->getRequest($clearingType);
        $paymentConf = $this->getPaymentConfiguration($clearingType);

        $cart = $this->persistCartToDatabase($event);

        $server = $this->getServer();
        if ($server !== '') {
            $request->setServer($server);
        }

        $request->setSecret($paymentConf['password']);
        $request->addParam('merchantId', $paymentConf['merchantId'])
            ->addParam('projectId', $paymentConf['projectId'])
            ->addParam('merchantTxId', md5((string)$this->orderItem->getOrderNumber()))
            ->addParam('amount', round($this->orderItem->getTotalGross() * 100))
            ->addParam('currency', $this->orderItem->getCurrencyCode())
            ->addParam(
                'purpose',
                LocalizationUtility::translate(
                    'tx_cartgirosolution.payment_request.purpose',
                    'CartGirosolution',
                    [
                        $this->orderItem->getOrderNumber(),
                    ]
                )
            )
            ->addParam('urlRedirect', $this->buildReturnUrl('redirect', $cart->getSHash()))
            ->addParam('urlNotify', $this->buildReturnUrl('notify', $cart->getSHash()));

        if ($clearingType === 'PAYPAL') {
            $request->addParam('type', 'SALE');
        }

        $request->submit();

        if ($request->requestHasSucceeded()) {
            $request->getResponseParam('rc');
            $request->getResponseParam('msg');
            $request->getResponseParam('reference');
            $request->getResponseParam('redirect');

            $transaction = new Transaction();
            $transaction->setTxnId((string)$request->getResponseParam('reference'));
            $transaction->setStatus('created');

            $payment->addTransaction($transaction);

            $this->paymentRepository->update($payment);
            $this->persistenceManager->persistAll();

            $request->redirectCustomerToPaymentProvider();
        } else {
            // if the transaction did not succeed update your local system, get the responsecode and notify the customer
            $request->getResponseParam('rc');
            $request->getResponseParam('msg');
            $request->getResponseMessage($request->getResponseParam('rc'), 'DE');
        }

        $event->setPropagationStopped(true);
    }

    private function getRequest(string $clearingType): GiroCheckout_SDK_Request
    {
        return match ($clearingType) {
            'CREDITCARD' => new GiroCheckout_SDK_Request('creditCardTransaction'),
            'PAYPAL' => new GiroCheckout_SDK_Request('paypalTransaction'),
            default => throw new InvalidArgumentException('Invalid Clearint Type', 1734099627),
        };
    }

    /**
     * Server alias (e.g. gc2-prod, gc2-preprod, gc1-prod) or base URL of the GiroCheckout API.
     * An empty value keeps the default of the SDK.
     */
    private function getServer(): string
    {
        try {
            return trim((string)$this->extensionConfiguration->get('cart_girosolution', 'server'));
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return '';
        }
    }

    private function getPaymentConfiguration(string $clearingType): array
    {
        return match ($clearingType) {
            'CREDITCARD' => $this->credentials['creditCard'],
            'PAYPAL' => $this->credentials['paypal'],
            default => throw new InvalidArgumentException('Invalid Clearint Type', 1734099627),
        };
    }

    /**
     * Builds a return URL to Cart order controller action
     */
    private function buildReturnUrl(string $action, string $hash): string
    {
        $arguments = [
            'tx_cartgirosolution_cart' => [
                'controller' => 'Order\Payment',
                'order' => $this->orderItem->getUid(),
                'action' => $action,
                'hash' => $hash,
            ],
            'type' => (int)$this->conf['redirectTypeNum'],
        ];

        return $this->pageRouter->generateUri($this->cartPid, $arguments)->__toString();
    }

    private function persistCartToDatabase(PaymentEvent $event): Cart
    {
        $cart = new Cart();
        $cart->setOrderItem($this->orderItem);
        $cart->setCart($event->getCart());
        $cart->setPid($this->orderPid);

        $this->cartRepository->add($cart);
        $this->persistenceManager->persistAll();

        return $cart;
    }
}
