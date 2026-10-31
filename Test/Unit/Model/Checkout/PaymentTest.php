<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Model\Checkout;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\InvalidRequestException;
use Stripe\Refund;
use Zero\Stripe\Model\Checkout\Gateway;
use Zero\Stripe\Model\Checkout\Payment;
use Zero\Stripe\Model\Config;

/**
 * Stripe Checkout refunds: the credit memo's amount in the order currency,
 * with a RefundKey idempotency key (the same on a retry of the same memo,
 * another for the next partial refund); minor() follows MinorUnits::STRIPE.
 *
 * ObjectManager's helper builds the method's parent dependencies as mocks.
 */
#[AllowMockObjectsWithoutExpectations]
class PaymentTest extends TestCase
{
    private Gateway&MockObject $gateway;
    private Payment $method;

    protected function setUp(): void
    {
        (new ObjectManager($this))->prepareObjectManager([
            [DirectoryHelper::class, $this->createStub(DirectoryHelper::class)],
        ]);
        $config = $this->createStub(Config::class);
        $config->method('getSecretKey')->willReturn('sk_test_1');
        $this->gateway = $this->createMock(Gateway::class);
        $this->method = (new ObjectManager($this))->getObject(Payment::class, [
            'config' => $config,
            'gateway' => $this->gateway,
        ]);
    }

    public function testRefundSendsIdempotencyKey(): void
    {
        $payment = $this->payment(8.0, 0.0);
        $this->gateway->expects($this->once())->method('refund')
            ->with('sk_test_1', 'pi_1', 800, 'zero-000000123-r0-1000')
            ->willReturn(Refund::constructFrom(['id' => 're_1', 'status' => 'succeeded']));
        $payment->expects($this->once())->method('setTransactionId')->with('re_1')->willReturnSelf();
        $payment->expects($this->once())->method('setIsTransactionClosed')->with(true)->willReturnSelf();

        $this->method->refund($payment, 10.0);
    }

    public function testSecondPartialRefundGetsAnotherKey(): void
    {
        $payment = $this->payment(4.0, 5.0);
        $payment->method('setTransactionId')->willReturnSelf();
        $this->gateway->expects($this->once())->method('refund')
            ->with('sk_test_1', 'pi_1', 400, 'zero-000000123-r500-500')
            ->willReturn(Refund::constructFrom(['id' => 're_2', 'status' => 'succeeded']));

        $this->method->refund($payment, 5.0);
    }

    public function testRefundWithoutCreditmemoUsesBaseTimesRate(): void
    {
        $payment = $this->payment(null, 0.0);
        $payment->method('setTransactionId')->willReturnSelf();
        $this->gateway->expects($this->once())->method('refund')
            ->with('sk_test_1', 'pi_1', 400, 'zero-000000123-r0-500')
            ->willReturn(Refund::constructFrom(['id' => 're_3', 'status' => 'succeeded']));

        $this->method->refund($payment, 5.0);
    }

    public function testRefusedRefundStopsTheCreditMemo(): void
    {
        $this->gateway->method('refund')->willThrowException(InvalidRequestException::factory('Charge already refunded'));
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Stripe refused the refund: Charge already refunded');

        $this->method->refund($this->payment(8.0, 0.0), 10.0);
    }

    public function testMinorDelegates(): void
    {
        $this->assertSame(12999, Payment::minor(129.99, 'USD'));
        $this->assertSame(1500, Payment::minor(1500, 'JPY'));
        $this->assertSame(500, Payment::minor(5.4, 'ISK'));
        $this->assertSame(700, Payment::minor(7, 'UGX'));
        $this->assertSame(10010, Payment::minor(10.005, 'KWD'));
        $this->assertSame(100, Payment::minor(100, 'MGA'));
    }

    /**
     * Order 123: base USD, shown in EUR at 0.8.
     */
    private function payment(?float $creditmemoGrandTotal, float $baseAmountRefunded): OrderPaymentInfo&MockObject
    {
        $order = $this->createStub(Order::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getBaseCurrencyCode')->willReturn('USD');
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getBaseToOrderRate')->willReturn(0.8);
        $payment = $this->createMock(OrderPaymentInfo::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAdditionalInformation')->willReturnMap([[Payment::INTENT_ID, 'pi_1']]);
        $payment->method('getBaseAmountRefunded')->willReturn($baseAmountRefunded);
        if ($creditmemoGrandTotal !== null) {
            $creditmemo = $this->createStub(Creditmemo::class);
            $creditmemo->method('getGrandTotal')->willReturn($creditmemoGrandTotal);
            $payment->method('getCreditmemo')->willReturn($creditmemo);
        }

        return $payment;
    }
}
