<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Stripe\Model\ReleaseIntent;
use Zero\Stripe\Observer\ReleaseFailedPlacement;

/**
 * Both failure events (Zero's zero_checkout_place_order_failed and Magento's
 * sales_model_service_quote_submit_failure) release a Stripe card hold, and
 * nothing is released for other methods or when the order was saved first.
 */
class ReleaseFailedPlacementTest extends TestCase
{
    private ReleaseIntent&MockObject $releaseIntent;

    protected function setUp(): void
    {
        $this->releaseIntent = $this->createMock(ReleaseIntent::class);
    }

    public function testCancelsThisQuotesHeldIntent(): void
    {
        $this->releaseIntent->expects($this->once())->method('release')->with(55, 'pi_1', 3);

        $this->observe(['quote' => $this->quote('zero_stripe', 'pi_1'), 'order_id' => null]);
    }

    public function testCoreSubmitFailureReadsTheOrderPaymentWhenTheQuoteHasNoIntent(): void
    {
        $orderPayment = $this->createStub(OrderPayment::class);
        $orderPayment->method('getMethod')->willReturn('zero_stripe');
        $orderPayment->method('getAdditionalInformation')->willReturnMap([['stripe_payment_intent_id', 'pi_2']]);
        $order = $this->createStub(Order::class);
        $order->method('getPayment')->willReturn($orderPayment);
        $this->releaseIntent->expects($this->once())->method('release')->with(55, 'pi_2', 3);

        $this->observe(['quote' => $this->quote('', ''), 'order' => $order]);
    }

    public function testNothingWhenTheOrderWasSavedBeforeTheFailure(): void
    {
        $this->releaseIntent->expects($this->never())->method('release');

        $this->observe(['quote' => $this->quote('zero_stripe', 'pi_1'), 'order_id' => '17']);
    }

    public function testIgnoresOtherMethods(): void
    {
        $this->releaseIntent->expects($this->never())->method('release');

        $this->observe(['quote' => $this->quote('checkmo', 'pi_1')]);
    }

    public function testIgnoresStripeWithoutIntent(): void
    {
        $this->releaseIntent->expects($this->never())->method('release');

        $this->observe(['quote' => $this->quote('zero_stripe', '')]);
    }

    public function testIgnoresEventWithoutQuote(): void
    {
        $this->releaseIntent->expects($this->never())->method('release');

        $this->observe(['quote' => null]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function observe(array $data): void
    {
        (new ReleaseFailedPlacement($this->releaseIntent))->execute(new Observer(['event' => new Event($data)]));
    }

    private function quote(string $method, string $intentId): Quote
    {
        $payment = $this->createStub(QuotePayment::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getAdditionalInformation')->willReturnMap([['stripe_payment_intent_id', $intentId]]);
        $quote = $this->createStub(Quote::class);
        $quote->method('getId')->willReturn(55);
        $quote->method('getStoreId')->willReturn(3);
        $quote->method('getPayment')->willReturn($payment);

        return $quote;
    }
}
