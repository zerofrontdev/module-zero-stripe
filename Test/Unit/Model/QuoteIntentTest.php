<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Zero\Stripe\Model\Config;
use Zero\Stripe\Model\IntentOrders;
use Zero\Stripe\Model\PaymentIntentGateway;
use Zero\Stripe\Model\QuoteIntent;

/**
 * The quote's card PaymentIntent: manual capture, sized in the quote
 * currency; a stored intent is updated, replaced, released or refused
 * depending on its status, and a held intent a saved order was placed with
 * is never cancelled (F1).
 */
#[AllowMockObjectsWithoutExpectations]
class QuoteIntentTest extends TestCase
{
    use MockCreationTrait;

    private const PARAMS = [
        'amount' => 2550,
        'currency' => 'usd',
        'metadata' => ['quote_id' => '55'],
        'capture_method' => 'manual',
    ];

    private PaymentIntentGateway&MockObject $gateway;
    private LoggerInterface&MockObject $logger;
    private IntentOrders&Stub $intentOrders;
    private Payment&MockObject $payment;
    private Quote&MockObject $quote;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(PaymentIntentGateway::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->intentOrders = $this->createStub(IntentOrders::class);
        $this->payment = $this->createPartialMock(Payment::class, ['save']);
        $this->quote = $this->quote(25.5, 'USD');
    }

    public function testCreatesManualCapture(): void
    {
        $this->gateway->expects($this->never())->method('retrieve');
        $this->gateway->expects($this->once())->method('create')
            ->with('sk_test_1', self::PARAMS + ['payment_method_types' => ['card']])
            ->willReturn($this->intent('pi_new', 'requires_payment_method'));
        $this->payment->expects($this->once())->method('save');

        $intent = $this->quoteIntent()->forQuote($this->quote);

        $this->assertSame('pi_new', $intent->id);
        $this->assertSame('pi_new', $this->payment->getAdditionalInformation(QuoteIntent::INTENT_ID));
    }

    public function testAmountInQuoteCurrencyMinorUnits(): void
    {
        $this->gateway->expects($this->once())->method('create')
            ->with('sk_test_1', $this->callback(
                fn (array $params) => $params['amount'] === 1000 && $params['currency'] === 'jpy'
            ))
            ->willReturn($this->intent('pi_jpy', 'requires_payment_method'));

        $this->quoteIntent()->forQuote($this->quote(1000.0, 'JPY'));
    }

    public function testUpdatesAnUpdatableIntentIncludingCaptureMethod(): void
    {
        $this->payment->setAdditionalInformation(QuoteIntent::INTENT_ID, 'pi_old');
        $this->gateway->method('retrieve')->with('sk_test_1', 'pi_old')
            ->willReturn($this->intent('pi_old', 'requires_confirmation'));
        $this->gateway->expects($this->once())->method('update')->with('sk_test_1', 'pi_old', self::PARAMS)
            ->willReturn($this->intent('pi_old', 'requires_confirmation'));
        $this->gateway->expects($this->never())->method('create');
        $this->gateway->expects($this->never())->method('cancel');

        $this->assertSame('pi_old', $this->quoteIntent()->forQuote($this->quote)->id);
    }

    public function testCancelsAHeldIntentAndCreatesNew(): void
    {
        $this->payment->setAdditionalInformation(QuoteIntent::INTENT_ID, 'pi_held');
        $this->gateway->method('retrieve')->willReturn($this->intent('pi_held', 'requires_capture', '55'));
        $this->intentOrders->method('orderFor')->willReturn(null);
        $this->gateway->expects($this->once())->method('cancel')->with('sk_test_1', 'pi_held')
            ->willReturn($this->intent('pi_held', 'canceled', '55'));
        $this->gateway->expects($this->once())->method('create')
            ->willReturn($this->intent('pi_new', 'requires_payment_method'));
        $this->gateway->expects($this->never())->method('update');

        $this->assertSame('pi_new', $this->quoteIntent()->forQuote($this->quote)->id);
        $this->assertSame('pi_new', $this->payment->getAdditionalInformation(QuoteIntent::INTENT_ID));
    }

    /**
     * F1: an observer after the order save threw, the quote stayed active; the
     * retry must not void order #000000077's hold.
     */
    public function testHeldIntentOfASavedOrderIsKeptAndANewOneCreated(): void
    {
        $this->payment->setAdditionalInformation(QuoteIntent::INTENT_ID, 'pi_held');
        $this->gateway->method('retrieve')->willReturn($this->intent('pi_held', 'requires_capture', '55'));
        $intentOrders = $this->createMock(IntentOrders::class);
        $intentOrders->expects($this->once())->method('orderFor')->with(55, 'pi_held')->willReturn('000000077');
        $this->gateway->expects($this->never())->method('cancel');
        $this->gateway->expects($this->once())->method('create')
            ->willReturn($this->intent('pi_new', 'requires_payment_method'));
        $this->logger->expects($this->once())->method('warning')->with(
            $this->logicalAnd(
                $this->stringContains('intent pi_held'),
                $this->stringContains('order #000000077')
            )
        );

        $this->assertSame('pi_new', $this->quoteIntent($intentOrders)->forQuote($this->quote)->id);
    }

    public function testHeldIntentOfAnotherQuoteIsNotCancelled(): void
    {
        $this->payment->setAdditionalInformation(QuoteIntent::INTENT_ID, 'pi_other');
        $this->gateway->method('retrieve')->willReturn($this->intent('pi_other', 'requires_capture', '99'));
        $this->gateway->expects($this->never())->method('cancel');
        $this->gateway->expects($this->once())->method('create')
            ->willReturn($this->intent('pi_new', 'requires_payment_method'));

        $this->assertSame('pi_new', $this->quoteIntent()->forQuote($this->quote)->id);
    }

    public function testSucceededIntentReplaced(): void
    {
        $this->payment->setAdditionalInformation(QuoteIntent::INTENT_ID, 'pi_paid');
        $this->gateway->method('retrieve')->willReturn($this->intent('pi_paid', 'succeeded', '55'));
        $this->gateway->expects($this->never())->method('cancel');
        $this->gateway->expects($this->never())->method('update');
        $this->gateway->expects($this->once())->method('create')
            ->willReturn($this->intent('pi_new', 'requires_payment_method'));
        $this->logger->expects($this->once())->method('warning')
            ->with($this->stringContains('quote 55 holds intent pi_paid that was already paid'));

        $this->assertSame('pi_new', $this->quoteIntent()->forQuote($this->quote)->id);
    }

    public function testCancelledIntentReplacedSilently(): void
    {
        $this->payment->setAdditionalInformation(QuoteIntent::INTENT_ID, 'pi_gone');
        $this->gateway->method('retrieve')->willReturn($this->intent('pi_gone', 'canceled', '55'));
        $this->gateway->expects($this->never())->method('cancel');
        $this->gateway->expects($this->once())->method('create')
            ->willReturn($this->intent('pi_new', 'requires_payment_method'));
        $this->logger->expects($this->never())->method('warning');

        $this->assertSame('pi_new', $this->quoteIntent()->forQuote($this->quote)->id);
    }

    public function testProcessingRefused(): void
    {
        $this->payment->setAdditionalInformation(QuoteIntent::INTENT_ID, 'pi_busy');
        $this->gateway->method('retrieve')->willReturn($this->intent('pi_busy', 'processing', '55'));
        $this->gateway->expects($this->never())->method('create');
        $this->gateway->expects($this->never())->method('cancel');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Your previous card payment is still being processed. Please wait a minute and try again.'
        );

        $this->quoteIntent()->forQuote($this->quote);
    }

    public function testStripeErrorBecomesGeneric(): void
    {
        $this->gateway->method('create')->willThrowException(InvalidRequestException::factory('boom'));
        $this->payment->expects($this->never())->method('save');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Could not start card payment. Please try again.');

        $this->quoteIntent()->forQuote($this->quote);
    }

    public function testEmptyCartRefused(): void
    {
        $this->gateway->expects($this->never())->method('create');
        $this->expectExceptionMessage('Your cart is empty.');

        $this->quoteIntent()->forQuote($this->quote(0.0, 'USD'));
    }

    private function quoteIntent(?IntentOrders $intentOrders = null): QuoteIntent
    {
        $config = $this->createStub(Config::class);
        $config->method('getSecretKey')->willReturn('sk_test_1');

        return new QuoteIntent($config, $this->gateway, $this->logger, $intentOrders ?? $this->intentOrders);
    }

    private function quote(float $grandTotal, string $currency): Quote&MockObject
    {
        $quote = $this->createPartialMockWithReflection(
            Quote::class,
            ['getId', 'getStoreId', 'getGrandTotal', 'getQuoteCurrencyCode', 'getPayment']
        );
        $quote->method('getId')->willReturn(55);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getGrandTotal')->willReturn($grandTotal);
        $quote->method('getQuoteCurrencyCode')->willReturn($currency);
        $quote->method('getPayment')->willReturn($this->payment);

        return $quote;
    }

    private function intent(string $id, string $status, ?string $quoteId = null): PaymentIntent
    {
        return PaymentIntent::constructFrom([
            'id' => $id,
            'status' => $status,
            'metadata' => $quoteId === null ? [] : ['quote_id' => $quoteId],
        ]);
    }
}
