<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model\Checkout;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Zero\Checkout\Controller\Checkout\Placeorder;
use Zero\Stripe\Model\Config;
use Zero\Checkout\Model\RedirectPayment\ReturnUrl;

/**
 * Stripe Checkout (zero_stripe_checkout): the customer pays on Stripe's own
 * page with whatever the Stripe account has switched on for the order's
 * currency and country: cards and wallets, Link, Afterpay/Clearpay, Affirm,
 * Klarna, Cash App Pay, iDEAL, Bancontact, SEPA, Przelewy24, BLIK… No script
 * on the store's pages. Placing the order makes a Checkout Session for its
 * total; back on the store, or by Stripe's webhook, OrderUpdater asks Stripe
 * how it went. Paid at once, or, with Hold Until Invoiced, authorized and
 * captured by an invoice (capture()); a cancel releases it. Credit memos
 * refund. Uses the Stripe card method's keys. Session modifiers
 * (SessionParamsInterface) can change the session, e.g. into a subscription.
 */
class Payment extends AbstractMethod
{
    public const CODE = 'zero_stripe_checkout';
    public const SESSION_ID = 'stripe_checkout_session_id';
    public const INTENT_ID = 'stripe_payment_intent_id';
    /** Currencies Stripe counts in whole units (no cents). */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = false;

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canVoid = true;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        private readonly Config $config,
        private readonly Gateway $gateway,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $log,
        private readonly ReturnUrl $returnUrl,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        private readonly array $sessionModifiers = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        return parent::isAvailable($quote) && $this->config->getSecretKey((int) $quote?->getStoreId()) !== '';
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     * @return $this
     * @throws LocalizedException
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        /** @var Order $order */
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();
        $reference = (string) $order->getIncrementId();
        $currency = (string) $order->getOrderCurrencyCode();
        $headless = $this->returnUrl->headless($order, self::CODE);
        $back = fn (bool $paid) => $headless ?? $this->urlBuilder->getUrl('zero_stripe/checkout/back', [
            '_secure' => true,
            '_query' => ['order' => $reference],
        ]) . ($paid ? '&session={CHECKOUT_SESSION_ID}' : '');
        $hold = $this->_scopeConfig->isSetFlag('payment/' . self::CODE . '/hold', ScopeInterface::SCOPE_STORE, $storeId);
        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => self::minor((float) $order->getGrandTotal(), $currency),
                    'product_data' => ['name' => (string) __('Order #%1', $reference)],
                ],
            ]],
            'client_reference_id' => $reference,
            'metadata' => ['order' => $reference],
            'payment_intent_data' => ['metadata' => ['order' => $reference]] + ($hold ? ['capture_method' => 'manual'] : []),
            'success_url' => $back(true),
            'cancel_url' => $back(false),
            // The store is the seller and works out its own tax; Stripe's Managed Payments
            // (Stripe as merchant of record, on by default for new accounts) would need a
            // Stripe tax code on every line and refuses the session without one.
            'managed_payments' => ['enabled' => false],
        ];
        if ($order->getCustomerEmail()) {
            $params['customer_email'] = (string) $order->getCustomerEmail();
        }
        foreach ($this->sessionModifiers as $modifier) {
            if ($modifier instanceof SessionParamsInterface) {
                $params = $modifier->modify($order, $params);
            }
        }
        try {
            $session = $this->gateway->createSession($this->secretKey($storeId), $params);
        } catch (ApiErrorException $e) {
            $this->log->warning('Zero Stripe Checkout: order ' . $reference . ': ' . $e->getMessage());
            throw new LocalizedException(__('Stripe couldn\'t start the payment: %1', $e->getMessage()));
        }

        $payment->setAdditionalInformation(self::SESSION_ID, (string) $session->id);
        $payment->setAdditionalInformation(Placeorder::REDIRECT_URL, (string) $session->url);
        $order->setCanSendNewEmailFlag(false);
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * Invoice with Capture Online on an authorized (held) payment.
     *
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function capture(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $intentId = $this->intentId($payment);
        try {
            $this->gateway->capture(
                $this->secretKey((int) $order->getStoreId()),
                $intentId,
                self::minor((float) $amount * (float) ($order->getBaseToOrderRate() ?: 1), (string) $order->getOrderCurrencyCode())
            );
        } catch (ApiErrorException $e) {
            throw new LocalizedException(__('Stripe refused the capture: %1', $e->getMessage()));
        }
        $payment->setTransactionId($intentId . '-capture')->setIsTransactionClosed(true);

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @return $this
     */
    public function void(InfoInterface $payment)
    {
        // Every cancellation passes here, unpaid ones too: an open session is
        // expired (so it can't be paid later), a held payment released; Stripe
        // being down doesn't stop the order from being cancelled.
        $order = $payment->getOrder();
        $secretKey = $this->config->getSecretKey((int) $order->getStoreId());
        $sessionId = (string) $payment->getAdditionalInformation(self::SESSION_ID);
        if ($secretKey === '' || $sessionId === '') {
            return $this;
        }
        try {
            $session = $this->gateway->session($secretKey, $sessionId);
            if ($session->status === 'open') {
                $this->gateway->expire($secretKey, $sessionId);
            }
            $intent = $session->payment_intent;
            if (is_object($intent) && $intent->status === 'requires_capture') {
                $this->gateway->cancel($secretKey, (string) $intent->id);
            }
        } catch (ApiErrorException $e) {
            $this->log->warning('Zero Stripe Checkout: could not release ' . $sessionId . ': ' . $e->getMessage());
        }

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @return $this
     */
    public function cancel(InfoInterface $payment)
    {
        return $this->void($payment);
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        try {
            $refund = $this->gateway->refund(
                $this->secretKey((int) $order->getStoreId()),
                $this->intentId($payment),
                self::minor((float) $amount * (float) ($order->getBaseToOrderRate() ?: 1), (string) $order->getOrderCurrencyCode())
            );
        } catch (ApiErrorException $e) {
            throw new LocalizedException(__('Stripe refused the refund: %1', $e->getMessage()));
        }
        $payment->setTransactionId((string) $refund->id)->setIsTransactionClosed(true);

        return $this;
    }

    /**
     * An amount in the currency's smallest unit, as Stripe takes it.
     */
    public static function minor(float $amount, string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }

    /**
     * @throws LocalizedException
     */
    private function secretKey(int $storeId): string
    {
        $key = $this->config->getSecretKey($storeId);
        if ($key === '') {
            throw new LocalizedException(__('Stripe is not set up: the secret key is missing.'));
        }

        return $key;
    }

    /**
     * @throws LocalizedException
     */
    private function intentId(InfoInterface $payment): string
    {
        $intentId = (string) $payment->getAdditionalInformation(self::INTENT_ID);
        if ($intentId === '') {
            throw new LocalizedException(__('This order has no Stripe payment.'));
        }

        return $intentId;
    }
}
