<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Zero\Checkout\Model\Money\MinorUnits;
use Zero\Checkout\Model\Money\RefundKey;

/**
 * Stripe card payment (zero_stripe), manual capture. The browser confirms the
 * PaymentIntent (stripe.confirmPayment() in web/js/checkout-stripe.js), which
 * only AUTHORIZES the card (capture_method=manual, QuoteIntent). Placing the
 * order calls authorize(), which verifies the intent: held (or, for an intent
 * made before manual capture, already paid), made for this order's quote, for
 * its grand total in its currency. Stripe doesn't know our order total and
 * the amount was set by our own endpoint, so the match is checked here.
 * CaptureAfterPlace then invoices the saved order online: capture() takes the
 * money. If the order isn't saved, ReleaseIntent cancels the hold.
 */
class Standard extends AbstractMethod
{
    /**
     * @var string
     */
    protected $_code = 'zero_stripe';

    /**
     * An online gateway: an invoice that can't be captured online is left
     * unpaid, never marked paid offline (Invoice::register, PayOperation).
     *
     * @var bool
     */
    protected $_isGateway = true;

    /**
     * @var bool
     */
    protected $_canAuthorize = true;

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * Stripe captures a held intent once: the whole authorization is invoiced.
     *
     * @var bool
     */
    protected $_canCapturePartial = false;

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
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param PaymentData $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param Config $stripeConfig
     * @param PaymentIntentGateway $paymentIntentGateway
     * @param LoggerInterface $log
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
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
        private readonly Config $stripeConfig,
        private readonly PaymentIntentGateway $paymentIntentGateway,
        private readonly LoggerInterface $log,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
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
     * Always authorize: the capture follows once the order is saved
     * (CaptureAfterPlace), whatever an old payment_action row says.
     *
     * @return string
     */
    public function getConfigPaymentAction()
    {
        return self::ACTION_AUTHORIZE;
    }

    /**
     * Copies stripe_payment_intent_id from additional_data into the payment's
     * additional information — the parent only dispatches an event for that
     * (Braintree registers an observer; one key doesn't justify one here).
     *
     * @param \Magento\Framework\DataObject $data
     * @return $this
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);

        $additionalData = $data->getData('additional_data');
        if (is_array($additionalData)
            && isset($additionalData['stripe_payment_intent_id'])
            && $additionalData['stripe_payment_intent_id'] !== ''
        ) {
            $this->getInfoInstance()->setAdditionalInformation(
                'stripe_payment_intent_id',
                (string) $additionalData['stripe_payment_intent_id']
            );
        }

        return $this;
    }

    /**
     * Places the order: checks the intent the browser confirmed, nothing is
     * charged yet.
     *
     * @param InfoInterface $payment
     * @param float $amount In the base currency (not used: the checks are in the order currency)
     * @return $this
     * @throws LocalizedException
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        /** @var Order $order */
        $order = $payment->getOrder();
        $intent = $this->intent($payment, (int) $order->getStoreId());

        if (!in_array($intent->status, [PaymentIntent::STATUS_REQUIRES_CAPTURE, PaymentIntent::STATUS_SUCCEEDED], true)) {
            throw new LocalizedException(
                __('Your card payment was not completed. Please try again or use a different payment method.')
            );
        }
        // The intent id comes from the browser's POST, and status + amount don't prove
        // it was confirmed for THIS order: an intent from an earlier order at the same total
        // would pass and could be replayed. QuoteIntent stamps metadata.quote_id server-side;
        // a quote is deactivated once ordered, so a match means "made for this checkout".
        $intentQuoteId = (string) ($intent->metadata['quote_id'] ?? '');
        if ($intentQuoteId === '' || $intentQuoteId !== (string) $order->getQuoteId()) {
            throw new LocalizedException(
                __('Your card payment could not be verified for this order. Please refresh the page and try again.')
            );
        }
        // What the customer was asked for: the grand total in the order (display) currency.
        $currency = (string) $order->getOrderCurrencyCode();
        $expectedAmount = MinorUnits::toMinor((float) $order->getGrandTotal(), $currency, MinorUnits::STRIPE);
        if ((int) $intent->amount !== $expectedAmount || strtolower((string) $intent->currency) !== strtolower($currency)) {
            throw new LocalizedException(
                __('The payment amount could not be verified for this order. Please contact support before trying again.')
            );
        }

        $payment->setTransactionId($intent->id);
        $payment->setIsTransactionClosed(false);
        $payment->setAdditionalInformation('stripe_payment_intent_status', $intent->status);

        return $this;
    }

    /**
     * Invoice with Capture Online: takes the held amount (all of it). An
     * intent that already succeeded (confirmed before manual capture) is not
     * captured again.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     */
    public function capture(InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $storeId = (int) $payment->getOrder()->getStoreId();
        $intent = $this->intent($payment, $storeId);

        if ($intent->status === PaymentIntent::STATUS_REQUIRES_CAPTURE) {
            try {
                $intent = $this->paymentIntentGateway->capture(
                    $this->stripeConfig->getSecretKey($storeId),
                    (string) $intent->id,
                    'zero-capture-' . $intent->id
                );
            } catch (ApiErrorException $e) {
                // Magento's invoicing replaces this message with a generic one: the reason stays in the log.
                $this->log->warning(sprintf(
                    'Zero Stripe: capture of intent %s refused: %s',
                    (string) $intent->id,
                    $e->getMessage()
                ), ['exception' => $e]);
                throw new LocalizedException(__('Stripe refused the capture: %1', $e->getMessage()));
            }
        }
        if ($intent->status !== PaymentIntent::STATUS_SUCCEEDED) {
            $this->log->warning(sprintf(
                'Zero Stripe: capture of intent %s refused: status %s',
                (string) $intent->id,
                (string) $intent->status
            ));
            throw new LocalizedException(__('Stripe refused the capture: %1', (string) $intent->status));
        }

        $payment->setTransactionId($intent->id . '-capture');
        $payment->setParentTransactionId($intent->id);
        $payment->setIsTransactionClosed(true);
        $payment->setAdditionalInformation('stripe_payment_intent_status', $intent->status);

        return $this;
    }

    /**
     * Releases a held authorization (Void, or the order cancelled). Stripe
     * being down doesn't stop the order from being voided: the hold expires
     * on its own after about 7 days.
     *
     * @param InfoInterface $payment
     * @return $this
     */
    public function void(InfoInterface $payment)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $intentId = (string) $payment->getAdditionalInformation('stripe_payment_intent_id');
        $secretKey = $this->stripeConfig->getSecretKey((int) $payment->getOrder()->getStoreId());
        if ($intentId === '' || $secretKey === '') {
            return $this;
        }
        try {
            $intent = $this->paymentIntentGateway->retrieve($secretKey, $intentId);
            if ($intent->status === PaymentIntent::STATUS_REQUIRES_CAPTURE) {
                $this->paymentIntentGateway->cancel($secretKey, $intentId);
            }
        } catch (ApiErrorException $e) {
            $this->log->warning('Zero Stripe: could not release ' . $intentId . ': ' . $e->getMessage());
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
     * A credit memo with "Refund": Stripe gives the amount back to the card
     * (all of it or part), in the order currency the intent was made in.
     *
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $order = $payment->getOrder();
        $intentId = (string) $payment->getAdditionalInformation('stripe_payment_intent_id');
        $secretKey = $this->stripeConfig->getSecretKey((int) $order->getStoreId());
        if ($intentId === '' || $secretKey === '') {
            throw new LocalizedException(__('This order has no Stripe payment to refund.'));
        }
        $creditmemo = $payment->getCreditmemo();
        $orderAmount = $creditmemo !== null && $creditmemo->getGrandTotal() !== null
            ? (float) $creditmemo->getGrandTotal()
            : (float) $amount * (float) ($order->getBaseToOrderRate() ?: 1);

        try {
            $refund = $this->paymentIntentGateway->refund(
                $secretKey,
                $intentId,
                MinorUnits::toMinor($orderAmount, (string) $order->getOrderCurrencyCode(), MinorUnits::STRIPE),
                RefundKey::for($payment, (float) $amount, 'zero-')
            );
        } catch (ApiErrorException $e) {
            throw new LocalizedException(__('Stripe refused the refund: %1', $e->getMessage()));
        }
        if (!in_array($refund->status, ['succeeded', 'pending'], true)) {
            throw new LocalizedException(__('Stripe refused the refund: %1', (string) $refund->status));
        }

        $payment->setTransactionId($refund->id);
        $payment->setParentTransactionId($intentId);
        $payment->setIsTransactionClosed(true);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function validate(): self
    {
        return $this;
    }

    /**
     * The PaymentIntent named on the payment, asked from Stripe.
     *
     * @throws LocalizedException
     */
    private function intent(InfoInterface $payment, int $storeId): PaymentIntent
    {
        $intentId = (string) $payment->getAdditionalInformation('stripe_payment_intent_id');
        if ($intentId === '') {
            throw new LocalizedException(
                __('Your card payment could not be verified. Please try again.')
            );
        }
        $secretKey = $this->stripeConfig->getSecretKey($storeId);
        if ($secretKey === '') {
            throw new LocalizedException(
                __('Stripe is not fully configured — a secret key is required.')
            );
        }
        try {
            return $this->paymentIntentGateway->retrieve($secretKey, $intentId);
        } catch (ApiErrorException $e) {
            throw new LocalizedException(
                __('We couldn\'t verify your card payment with Stripe. Please try again.')
            );
        }
    }
}
