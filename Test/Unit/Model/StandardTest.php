<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Zero\Stripe\Model\Config;
use Zero\Stripe\Model\PaymentIntentGateway;
use Zero\Stripe\Model\Standard;

/**
 * Manual capture: authorize() only accepts a held (or legacy succeeded)
 * PaymentIntent made for this order's quote, for its grand total in the order
 * currency; capture() takes the money once, with an idempotency key; void()
 * releases only a held intent; refund() gives money back through Stripe in
 * the order currency with a RefundKey.
 */
#[AllowMockObjectsWithoutExpectations]
class StandardTest extends TestCase
{
    private Config&MockObject $config;
    private PaymentIntentGateway&MockObject $gateway;
    private LoggerInterface&MockObject $log;
    private Standard $method;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->gateway = $this->createMock(PaymentIntentGateway::class);
        $this->log = $this->createMock(LoggerInterface::class);
        // AbstractMethod falls back to the global ObjectManager for its DirectoryHelper.
        (new ObjectManager($this))->prepareObjectManager([
            [DirectoryHelper::class, $this->createStub(DirectoryHelper::class)],
        ]);
        $this->method = $this->method($this->config);
        $this->config->method('getSecretKey')->willReturn('sk_test_123');
    }

    public function testPaymentActionIsAuthorize(): void
    {
        $this->assertSame(AbstractMethod::ACTION_AUTHORIZE, $this->method->getConfigPaymentAction());
    }

    public function testManualCaptureFlags(): void
    {
        $this->assertTrue($this->method->canAuthorize());
        $this->assertTrue($this->method->canCapture());
        $this->assertFalse($this->method->canCapturePartial());
        $this->assertTrue($this->method->isGateway());
        $this->assertTrue($this->method->canRefund());
    }

    public function testAuthorizeAcceptsRequiresCaptureForThisQuoteAmountAndOrderCurrency(): void
    {
        $payment = $this->payment('pi_1', $this->order(19.99, 'USD', 42));
        $this->gateway->expects($this->once())->method('retrieve')
            ->with('sk_test_123', 'pi_1')
            ->willReturn($this->intent('requires_capture', 1999, 'usd', '42'));
        $this->gateway->expects($this->never())->method('capture');

        $payment->expects($this->once())->method('setTransactionId')->with('pi_1');
        $payment->expects($this->once())->method('setIsTransactionClosed')->with(false);
        $payment->expects($this->once())->method('setAdditionalInformation')
            ->with('stripe_payment_intent_status', 'requires_capture');

        $this->assertSame($this->method, $this->method->authorize($payment, 19.99));
    }

    public function testAuthorizeAcceptsLegacySucceededIntent(): void
    {
        $payment = $this->payment('pi_1', $this->order(10, 'USD', 3));
        $this->gateway->method('retrieve')->willReturn($this->intent('succeeded', 1000, 'usd', '3'));
        $payment->expects($this->once())->method('setAdditionalInformation')
            ->with('stripe_payment_intent_status', 'succeeded');

        $this->method->authorize($payment, 10);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unfinishedStatuses(): array
    {
        return [
            'requires_payment_method' => ['requires_payment_method'],
            'requires_action' => ['requires_action'],
            'processing' => ['processing'],
            'canceled' => ['canceled'],
        ];
    }

    #[DataProvider('unfinishedStatuses')]
    public function testAuthorizeRejectsUnfinishedIntent(string $status): void
    {
        $payment = $this->payment('pi_1', $this->order(10, 'USD', 1));
        $this->gateway->method('retrieve')->willReturn($this->intent($status, 1000, 'usd', '1'));
        $payment->expects($this->never())->method('setTransactionId');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Your card payment was not completed.');

        $this->method->authorize($payment, 10);
    }

    /**
     * Replay: a held intent with the right amount, but made for another quote.
     */
    public function testAuthorizeRejectsOtherQuote(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 1000, 'usd', '7'));
        $this->expectExceptionMessage('could not be verified for this order');

        $this->method->authorize($this->payment('pi_1', $this->order(10, 'USD', 8)), 10);
    }

    public function testAuthorizeRejectsIntentWithoutQuoteMetadata(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 1000, 'usd', null));
        $this->expectExceptionMessage('could not be verified for this order');

        $this->method->authorize($this->payment('pi_1', $this->order(10, 'USD', 8)), 10);
    }

    public function testAuthorizeRejectsAmountMismatch(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 999, 'usd', '3'));
        $this->expectExceptionMessage('payment amount could not be verified');

        $this->method->authorize($this->payment('pi_1', $this->order(10, 'USD', 3)), 10);
    }

    public function testAuthorizeRejectsCurrencyMismatch(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 1000, 'eur', '3'));
        $this->expectExceptionMessage('payment amount could not be verified');

        $this->method->authorize($this->payment('pi_1', $this->order(10, 'USD', 3)), 10);
    }

    /**
     * A3-8: base 10 USD shown as 8 EUR (rate 0.8). An intent for the base amount is refused.
     */
    public function testAuthorizeRejectsBaseAmountWhenOrderCurrencyDiffers(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 1000, 'usd', '3'));
        $this->expectExceptionMessage('payment amount could not be verified');

        $this->method->authorize($this->payment('pi_1', $this->order(8.0, 'EUR', 3, 10.0, 'USD')), 10.0);
    }

    public function testAuthorizeAcceptsOrderCurrencyAmountWhenItDiffersFromBase(): void
    {
        $payment = $this->payment('pi_1', $this->order(8.0, 'EUR', 3, 10.0, 'USD'));
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 800, 'eur', '3'));
        $payment->expects($this->once())->method('setTransactionId')->with('pi_1');

        $this->method->authorize($payment, 10.0);
    }

    /**
     * @return array<string, array{float, string, int}>
     */
    public static function zeroAndThreeDecimalCurrencies(): array
    {
        return [
            'JPY 1000' => [1000.0, 'JPY', 1000],
            'KWD 10.005' => [10.005, 'KWD', 10010],
        ];
    }

    #[DataProvider('zeroAndThreeDecimalCurrencies')]
    public function testAuthorizeJpyAndKwd(float $grandTotal, string $currency, int $minor): void
    {
        $payment = $this->payment('pi_1', $this->order($grandTotal, $currency, 5));
        $this->gateway->method('retrieve')
            ->willReturn($this->intent('requires_capture', $minor, strtolower($currency), '5'));
        $payment->expects($this->once())->method('setTransactionId')->with('pi_1');

        $this->method->authorize($payment, $grandTotal);
    }

    public function testAuthorizeRejectsMissingIntentId(): void
    {
        $this->gateway->expects($this->never())->method('retrieve');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Your card payment could not be verified. Please try again.');

        $this->method->authorize($this->payment('', $this->order(10, 'USD', 1)), 10);
    }

    public function testAuthorizeRejectsWhenSecretKeyMissing(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getSecretKey')->willReturn('');
        $this->gateway->expects($this->never())->method('retrieve');
        $this->expectExceptionMessage('secret key is required');

        $this->method($config)->authorize($this->payment('pi_1', $this->order(10, 'USD', 1)), 10);
    }

    public function testAuthorizeWrapsStripeApiErrors(): void
    {
        $this->gateway->method('retrieve')->willThrowException(InvalidRequestException::factory('No such intent'));
        $this->expectExceptionMessage('couldn\'t verify your card payment');

        $this->method->authorize($this->payment('pi_1', $this->order(10, 'USD', 1)), 10);
    }

    public function testCaptureCapturesWithIdempotencyKey(): void
    {
        $payment = $this->payment('pi_1', $this->order(10, 'USD', 1));
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 1000, 'usd', '1'));
        $this->gateway->expects($this->once())->method('capture')
            ->with('sk_test_123', 'pi_1', 'zero-capture-pi_1')
            ->willReturn($this->intent('succeeded', 1000, 'usd', '1'));

        $payment->expects($this->once())->method('setTransactionId')->with('pi_1-capture');
        $payment->expects($this->once())->method('setParentTransactionId')->with('pi_1');
        $payment->expects($this->once())->method('setIsTransactionClosed')->with(true);
        $payment->expects($this->once())->method('setAdditionalInformation')
            ->with('stripe_payment_intent_status', 'succeeded');

        $this->assertSame($this->method, $this->method->capture($payment, 10));
    }

    public function testCaptureOfSucceededIntentMakesNoCall(): void
    {
        $payment = $this->payment('pi_1', $this->order(10, 'USD', 1));
        $this->gateway->method('retrieve')->willReturn($this->intent('succeeded', 1000, 'usd', '1'));
        $this->gateway->expects($this->never())->method('capture');

        $payment->expects($this->once())->method('setTransactionId')->with('pi_1-capture');
        $payment->expects($this->once())->method('setParentTransactionId')->with('pi_1');
        $payment->expects($this->once())->method('setIsTransactionClosed')->with(true);

        $this->method->capture($payment, 10);
    }

    public function testCaptureThatDoesNotSucceedIsRefusedAndLogged(): void
    {
        $payment = $this->payment('pi_1', $this->order(10, 'USD', 1));
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 1000, 'usd', '1'));
        $this->gateway->method('capture')->willReturn($this->intent('processing', 1000, 'usd', '1'));
        $this->log->expects($this->once())->method('warning')
            ->with('Zero Stripe: capture of intent pi_1 refused: status processing');
        $payment->expects($this->never())->method('setTransactionId');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Stripe refused the capture: processing');

        $this->method->capture($payment, 10);
    }

    public function testCaptureOfCancelledIntentMakesNoCallAndIsRefused(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('canceled', 1000, 'usd', '1'));
        $this->gateway->expects($this->never())->method('capture');
        $this->expectExceptionMessage('Stripe refused the capture: canceled');

        $this->method->capture($this->payment('pi_1', $this->order(10, 'USD', 1)), 10);
    }

    public function testCaptureApiErrorIsLoggedWithStripesReasonAndThrown(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 1000, 'usd', '1'));
        $this->gateway->method('capture')->willThrowException(InvalidRequestException::factory('Hold expired'));
        $this->log->expects($this->once())->method('warning')->with(
            'Zero Stripe: capture of intent pi_1 refused: Hold expired',
            $this->callback(fn (array $context) => $context['exception'] instanceof InvalidRequestException)
        );
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Stripe refused the capture: Hold expired');

        $this->method->capture($this->payment('pi_1', $this->order(10, 'USD', 1)), 10);
    }

    public function testVoidCancelsHeldIntent(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 1000, 'usd', '1'));
        $this->gateway->expects($this->once())->method('cancel')->with('sk_test_123', 'pi_1')
            ->willReturn($this->intent('canceled', 1000, 'usd', '1'));
        $this->log->expects($this->never())->method('warning');

        $this->assertSame($this->method, $this->method->void($this->payment('pi_1', $this->order(10, 'USD', 1))));
    }

    public function testVoidSwallowsApiErrors(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 1000, 'usd', '1'));
        $this->gateway->method('cancel')->willThrowException(InvalidRequestException::factory('Stripe down'));
        $this->log->expects($this->once())->method('warning')
            ->with('Zero Stripe: could not release pi_1: Stripe down');

        $this->assertSame($this->method, $this->method->void($this->payment('pi_1', $this->order(10, 'USD', 1))));
    }

    public function testVoidLeavesACapturedIntentAlone(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('succeeded', 1000, 'usd', '1'));
        $this->gateway->expects($this->never())->method('cancel');

        $this->method->void($this->payment('pi_1', $this->order(10, 'USD', 1)));
    }

    public function testVoidWithoutIntentDoesNothing(): void
    {
        $this->gateway->expects($this->never())->method('retrieve');
        $this->gateway->expects($this->never())->method('cancel');

        $this->method->void($this->payment('', $this->order(10, 'USD', 1)));
    }

    public function testCancelReleasesTheHeldIntent(): void
    {
        $this->gateway->method('retrieve')->willReturn($this->intent('requires_capture', 1000, 'usd', '1'));
        $this->gateway->expects($this->once())->method('cancel')->with('sk_test_123', 'pi_1')
            ->willReturn($this->intent('canceled', 1000, 'usd', '1'));

        $this->assertSame($this->method, $this->method->cancel($this->payment('pi_1', $this->order(10, 'USD', 1))));
    }

    /**
     * Base 10 USD refunded; the memo is 8 EUR in the order currency: Stripe gets 800 with the RefundKey.
     */
    public function testRefundInOrderCurrencyWithRefundKey(): void
    {
        $payment = $this->payment('pi_1', $this->order(8.0, 'EUR', 42, 10.0, 'USD', '000000123'), 8.0);
        $this->gateway->expects($this->once())->method('refund')
            ->with('sk_test_123', 'pi_1', 800, 'zero-000000123-r0-1000')
            ->willReturn(Refund::constructFrom(['id' => 're_1', 'status' => 'succeeded']));

        $payment->expects($this->once())->method('setTransactionId')->with('re_1');
        $payment->expects($this->once())->method('setParentTransactionId')->with('pi_1');
        $payment->expects($this->once())->method('setIsTransactionClosed')->with(true);

        $this->method->refund($payment, 10.0);
    }

    public function testRefundWithoutCreditmemoUsesBaseTimesRate(): void
    {
        $payment = $this->payment('pi_1', $this->order(8.0, 'EUR', 42, 10.0, 'USD', '000000123', 0.8));
        $this->gateway->expects($this->once())->method('refund')
            ->with('sk_test_123', 'pi_1', 400, 'zero-000000123-r0-500')
            ->willReturn(Refund::constructFrom(['id' => 're_2', 'status' => 'pending']));

        $this->method->refund($payment, 5.0);
    }

    public function testSecondPartialRefundGetsAnotherKey(): void
    {
        $payment = $this->payment('pi_1', $this->order(20.0, 'USD', 42, 20.0, 'USD', '000000123'), 10.0, 10.0);
        $this->gateway->expects($this->once())->method('refund')
            ->with('sk_test_123', 'pi_1', 1000, 'zero-000000123-r1000-1000')
            ->willReturn(Refund::constructFrom(['id' => 're_3', 'status' => 'succeeded']));

        $this->method->refund($payment, 10.0);
    }

    public function testRefundStopsTheCreditMemoWhenStripeRefuses(): void
    {
        $this->gateway->method('refund')->willThrowException(InvalidRequestException::factory('Charge already refunded'));
        $this->expectExceptionMessage('Stripe refused the refund: Charge already refunded');

        $this->method->refund($this->payment('pi_1', $this->order(10, 'USD', 1)), 10);
    }

    public function testRefundStopsOnAFailedRefund(): void
    {
        $this->gateway->method('refund')->willReturn(Refund::constructFrom(['id' => 're_1', 'status' => 'failed']));
        $this->expectExceptionMessage('Stripe refused the refund: failed');

        $this->method->refund($this->payment('pi_1', $this->order(10, 'USD', 1)), 10);
    }

    public function testRefundNeedsTheIntent(): void
    {
        $this->gateway->expects($this->never())->method('refund');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This order has no Stripe payment to refund.');

        $this->method->refund($this->payment('', $this->order(10, 'USD', 1)), 10);
    }

    public function testAssignDataStoresIntentId(): void
    {
        $info = $this->createMock(InfoInterface::class);
        $info->expects($this->once())->method('setAdditionalInformation')
            ->with('stripe_payment_intent_id', 'pi_99');
        $this->method->setInfoInstance($info);

        $this->method->assignData(new DataObject(['additional_data' => ['stripe_payment_intent_id' => 'pi_99']]));
    }

    public function testAssignDataIgnoresEmptyIntentId(): void
    {
        $info = $this->createMock(InfoInterface::class);
        $info->expects($this->never())->method('setAdditionalInformation');
        $this->method->setInfoInstance($info);

        $this->method->assignData(new DataObject(['additional_data' => ['stripe_payment_intent_id' => '']]));
    }

    private function method(Config $config): Standard
    {
        return (new ObjectManager($this))->getObject(Standard::class, [
            'stripeConfig' => $config,
            'paymentIntentGateway' => $this->gateway,
            'log' => $this->log,
        ]);
    }

    private function order(
        float $grandTotal,
        string $currency,
        int $quoteId,
        ?float $baseGrandTotal = null,
        ?string $baseCurrency = null,
        string $incrementId = '000000001',
        float $baseToOrderRate = 1.0
    ): Order&MockObject {
        $order = $this->createMock(Order::class);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getOrderCurrencyCode')->willReturn($currency);
        $order->method('getBaseGrandTotal')->willReturn($baseGrandTotal ?? $grandTotal);
        $order->method('getBaseCurrencyCode')->willReturn($baseCurrency ?? $currency);
        $order->method('getBaseToOrderRate')->willReturn($baseToOrderRate);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getQuoteId')->willReturn($quoteId);
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }

    private function payment(
        string $intentId,
        Order $order,
        ?float $creditmemoGrandTotal = null,
        float $baseAmountRefunded = 0.0
    ): Payment&MockObject {
        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAdditionalInformation')->willReturnMap([
            ['stripe_payment_intent_id', $intentId],
        ]);
        $payment->method('getBaseAmountRefunded')->willReturn($baseAmountRefunded);
        if ($creditmemoGrandTotal !== null) {
            $creditmemo = $this->createStub(Creditmemo::class);
            $creditmemo->method('getGrandTotal')->willReturn($creditmemoGrandTotal);
            $payment->method('getCreditmemo')->willReturn($creditmemo);
        }

        return $payment;
    }

    private function intent(string $status, int $amount, string $currency, ?string $quoteId): PaymentIntent
    {
        return PaymentIntent::constructFrom([
            'id' => 'pi_1',
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => $quoteId === null ? [] : ['quote_id' => $quoteId],
        ]);
    }
}
