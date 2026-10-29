<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Controller\Standard;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Zero\Stripe\Model\QuoteIntent;

/**
 * POST endpoint web/js/checkout-stripe.js calls (on mount and again right before
 * confirming): creates a PaymentIntent for the quote's grand total, or updates
 * the one already stored on the quote payment (stripe_payment_intent_id). Every
 * intent is stamped with metadata.quote_id, which Model\Standard::capture() checks.
 *
 * CSRF: automatic check opted out, form key validated manually in execute().
 */
class Intent implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly QuoteIntent $quoteIntent
    ) {
    }

    /**
     * @return Json
     */
    public function execute()
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        if (!$this->formKeyValidator->validate($this->request)) {
            return $result->setHttpResponseCode(400)->setData(['error' => (string) __('Your session has expired. Please refresh and try again.')]);
        }

        $quote = $this->checkoutSession->getQuote();
        if (!$quote->getId()) {
            return $result->setHttpResponseCode(400)->setData(['error' => (string) __('Your cart is empty.')]);
        }

        // Apply the chosen shipping method before sizing the intent: until Placeorder
        // the quote has rates but no method, so the amount would exclude shipping and
        // capture()'s amount check would then (correctly) reject the order. Same few
        // lines as Placeorder.
        $shippingMethod = (string) ($this->request->getParam('shipping_method') ?? '');
        if ($shippingMethod !== '') {
            $shippingAddress = $quote->getShippingAddress();
            $rate = $shippingAddress->getShippingRateByCode($shippingMethod);
            if ($rate) {
                $shippingAddress->setShippingMethod($shippingMethod);
                // Recollect rates (as Placeorder does): this total feeds capture()'s amount check.
                $shippingAddress->setCollectShippingRates(true);
                $quote->setTotalsCollectedFlag(false);
                $quote->collectTotals();
            }
        }

        try {
            $intent = $this->quoteIntent->forQuote($quote);
        } catch (LocalizedException $e) {
            return $result->setHttpResponseCode(400)->setData(['error' => $e->getMessage()]);
        }

        return $result->setData(['client_secret' => $intent->client_secret]);
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
