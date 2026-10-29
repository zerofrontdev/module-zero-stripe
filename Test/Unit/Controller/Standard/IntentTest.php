<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Stripe\Test\Unit\Controller\Standard;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Zero\Stripe\Controller\Standard\Intent;
use Zero\Stripe\Model\Config;
use Zero\Stripe\Model\PaymentIntentGateway;
use Zero\Stripe\Model\QuoteIntent;

/**
 * The intent endpoint sizes the PaymentIntent from the quote and stamps
 * metadata.quote_id, which capture() later requires.
 */
#[AllowMockObjectsWithoutExpectations]
class IntentTest extends TestCase
{
    use MockCreationTrait;

    private RequestInterface $request;
    private Json&MockObject $json;
    private FormKeyValidator $formKeyValidator;
    private Config $config;
    private PaymentIntentGateway&MockObject $gateway;
    private Quote&MockObject $quote;
    private Payment&MockObject $payment;
    private bool $formKeyValid = true;
    /** @var array<string, mixed> */
    private array $responseData = [];
    private ?int $responseCode = null;

    protected function setUp(): void
    {
        $this->request = $this->createStub(RequestInterface::class);
        $this->formKeyValidator = $this->createStub(FormKeyValidator::class);
        $this->formKeyValidator->method('validate')->willReturnCallback(fn () => $this->formKeyValid);

        $this->json = $this->createMock(Json::class);
        $this->json->method('setData')->willReturnCallback(function (array $data) {
            $this->responseData = $data;
            return $this->json;
        });
        $this->json->method('setHttpResponseCode')->willReturnCallback(function (int $code) {
            $this->responseCode = $code;
            return $this->json;
        });

        $this->payment = $this->createPartialMock(Payment::class, ['save']);
        $this->quote = $this->createPartialMockWithReflection(
            Quote::class,
            ['getId', 'getStoreId', 'getGrandTotal', 'getQuoteCurrencyCode', 'getPayment', 'getShippingAddress']
        );
        $this->quote->method('getId')->willReturn(55);
        $this->quote->method('getStoreId')->willReturn(1);
        $this->quote->method('getQuoteCurrencyCode')->willReturn('USD');
        $this->quote->method('getPayment')->willReturn($this->payment);
        $this->quote->method('getShippingAddress')->willReturn($this->createStub(Address::class));

        $this->config = $this->createStub(Config::class);
        $this->config->method('getSecretKey')->willReturn('sk_test_1');
        $this->gateway = $this->createMock(PaymentIntentGateway::class);
    }

    public function testCreatesCardIntentBoundToQuote(): void
    {
        $this->quote->method('getGrandTotal')->willReturn(25.5);
        $this->gateway->expects($this->once())->method('create')->with('sk_test_1', [
            'amount' => 2550,
            'currency' => 'usd',
            'payment_method_types' => ['card'],
            'metadata' => ['quote_id' => '55'],
        ])->willReturn(PaymentIntent::constructFrom(['id' => 'pi_new', 'client_secret' => 'secret_1']));
        $this->gateway->expects($this->never())->method('update');
        $this->payment->expects($this->once())->method('save');

        $this->controller()->execute();

        $this->assertSame(['client_secret' => 'secret_1'], $this->responseData);
        $this->assertSame('pi_new', $this->payment->getAdditionalInformation('stripe_payment_intent_id'));
    }

    public function testUpdatesExistingIntentWithCurrentTotal(): void
    {
        $this->quote->method('getGrandTotal')->willReturn(30.0);
        $this->payment->setAdditionalInformation('stripe_payment_intent_id', 'pi_old');
        $this->gateway->expects($this->once())->method('update')->with('sk_test_1', 'pi_old', [
            'amount' => 3000,
            'currency' => 'usd',
            'metadata' => ['quote_id' => '55'],
        ])->willReturn(PaymentIntent::constructFrom(['id' => 'pi_old', 'client_secret' => 'secret_2']));
        $this->gateway->expects($this->never())->method('create');

        $this->controller()->execute();

        $this->assertSame(['client_secret' => 'secret_2'], $this->responseData);
    }

    public function testRejectsInvalidFormKey(): void
    {
        $this->formKeyValid = false;
        $this->gateway->expects($this->never())->method('create');

        $this->controller()->execute();

        $this->assertSame(400, $this->responseCode);
    }

    public function testRejectsEmptyCart(): void
    {
        $this->quote->method('getGrandTotal')->willReturn(0.0);
        $this->gateway->expects($this->never())->method('create');

        $this->controller()->execute();

        $this->assertSame(400, $this->responseCode);
    }

    public function testStripeErrorBecomesGenericMessage(): void
    {
        $this->quote->method('getGrandTotal')->willReturn(10.0);
        $this->gateway->method('create')->willThrowException(InvalidRequestException::factory('boom'));

        $this->controller()->execute();

        $this->assertSame(400, $this->responseCode);
        $this->assertSame('Could not start card payment. Please try again.', $this->responseData['error']);
    }

    private function controller(): Intent
    {
        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturn($this->json);
        $session = $this->createStub(CheckoutSession::class);
        $session->method('getQuote')->willReturn($this->quote);

        return new Intent(
            $this->request,
            $resultFactory,
            $session,
            $this->formKeyValidator,
            new QuoteIntent($this->config, $this->gateway)
        );
    }
}
