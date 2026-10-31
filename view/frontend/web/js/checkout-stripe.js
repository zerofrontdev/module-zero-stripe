/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Stripe for Zero Checkout, plain JS: Payment Element and Express Checkout
 * share one `elements` and one PaymentIntent. The intent is manual-capture:
 * confirmPayment() only authorizes the card in the browser (status
 * requires_capture), then the form posts the intent id; Model\Standard::authorize()
 * checks it and the order captures it once saved. The intent's amount is
 * refreshed on mount and right before confirming (the total can change; the
 * client_secret stays). When the server hands back another intent (the old
 * one was held by a placement that failed, or was already used), Elements are
 * built again for it and the customer re-enters the card. Not handled:
 * off-site 3-D Secure redirects.
 */
(function () {
    'use strict';

    // Settings rendered by the template as JSON (no inline script: strict CSP).
    var settingsEl = document.getElementById('zero-stripe-config');
    var settings = settingsEl ? JSON.parse(settingsEl.textContent) : {};

    var form = document.getElementById('checkout-form');
    var mountPoint = document.getElementById('stripe-payment-element');
    if (!form || !mountPoint || !settings.publishableKey || !window.Stripe || !settings.intentUrl) {
        return;
    }

    var stripe = window.Stripe(settings.publishableKey);
    var errorBox = document.getElementById('stripe-error');
    var intentInput = document.getElementById('stripe_payment_intent_id');
    var submitButton = form.querySelector('button[type="submit"]');
    var expressWrap = document.getElementById('stripe-express-checkout-wrap');
    var expressMount = document.getElementById('stripe-express-checkout');
    var elements = null;
    var elementsReady = null;
    var clientSecretInUse = null;
    var paymentElement = null;
    var expressCheckoutElement = null;

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('hidden');
    }

    function clearError() {
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    }

    function isStripeChecked() {
        var checked = form.querySelector('input[name="payment_method"]:checked');
        return !!checked && checked.value === 'zero_stripe';
    }

    /**
     * A wallet confirm can happen without the zero_stripe radio being checked;
     * Placeorder reads payment_method, so make sure it says zero_stripe.
     */
    function checkStripeRadio() {
        var radio = form.querySelector('input[name="payment_method"][value="zero_stripe"]');
        if (radio && !radio.checked) {
            radio.checked = true;
        }
    }

    function getFormKey() {
        var input = form.querySelector('input[name="form_key"]');
        return input ? input.value : '';
    }

    /**
     * Create/update the PaymentIntent (zero_stripe/standard/intent). Sends the
     * chosen shipping_method so the amount includes shipping — otherwise
     * capture()'s amount check rejects the order.
     *
     * @return {Promise<string>} resolves to a client_secret
     */
    function fetchIntent() {
        var body = new URLSearchParams();
        body.set('form_key', getFormKey());
        var shippingRadio = form.querySelector('input[name="shipping_method"]:checked');
        if (shippingRadio) {
            body.set('shipping_method', shippingRadio.value);
        }

        return fetch(settings.intentUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || data.error) {
                        throw new Error(data.error || 'Could not start card payment.');
                    }
                    return data.client_secret;
                });
            });
    }

    /**
     * Mounts the Payment Element and Express Checkout Element from one
     * `elements` group. Runs eagerly (wallet buttons must show immediately);
     * the card panel itself stays hidden by its CSS :has() toggle until chosen.
     */
    function ensureElements() {
        if (elementsReady) {
            return elementsReady;
        }

        elementsReady = fetchIntent().then(function (clientSecret) {
            mountElements(clientSecret);
            return elements;
        });

        return elementsReady;
    }

    /**
     * Builds `elements` for one client_secret, replacing any built before.
     *
     * @param {string} clientSecret
     */
    function mountElements(clientSecret) {
        if (paymentElement) {
            paymentElement.destroy();
            paymentElement = null;
        }
        if (expressCheckoutElement) {
            expressCheckoutElement.destroy();
            expressCheckoutElement = null;
        }
        clientSecretInUse = clientSecret;
        elements = stripe.elements({ clientSecret: clientSecret });
        // The account's own payment-method configuration still adds Bank/Klarna/Link
        // tabs to a card-only intent; paymentMethodOrder: ['card'] restricts the rendered
        // tabs (paymentMethodTypes isn't a real option here and is silently ignored).
        paymentElement = elements.create('payment', { paymentMethodOrder: ['card'] });
        paymentElement.mount('#stripe-element-mount');
        mountExpressCheckout();
    }

    /**
     * Right before confirming: resizes the intent to the live quote total. If
     * the server answers with another intent, the card form is rebuilt for it
     * and the confirm stops: the details typed belong to the old one.
     *
     * @return {Promise<void>}
     */
    function syncIntent() {
        return fetchIntent().then(function (clientSecret) {
            if (clientSecret !== clientSecretInUse) {
                mountElements(clientSecret);
                throw new Error('Please re-enter your card details.');
            }
        });
    }

    /**
     * Google Pay / Apple Pay buttons, payment only (the form already has the
     * shipping address). Shown only when `availablepaymentmethodschange` reports
     * a wallet this browser supports.
     */
    function mountExpressCheckout() {
        if (!expressWrap || !expressMount || !elements) {
            return;
        }

        expressCheckoutElement = elements.create('expressCheckout', {});

        expressCheckoutElement.on('availablepaymentmethodschange', function (event) {
            var available = event && event.paymentMethods;
            expressWrap.style.display = available ? 'flex' : 'none';
            // Decided, shown or not: the payment list below won't move again.
            expressWrap.setAttribute('data-express-ready', '');
        });

        expressCheckoutElement.on('confirm', function () {
            clearError();
            checkStripeRadio();
            if (submitButton) {
                submitButton.disabled = true;
            }

            elements.submit()
                .then(function (submitResult) {
                    if (submitResult && submitResult.error) {
                        throw submitResult.error;
                    }
                    // Sync the intent amount to the live quote total right before confirming.
                    return syncIntent();
                })
                .then(function () {
                    return stripe.confirmPayment({
                        elements: elements,
                        confirmParams: { return_url: window.location.href },
                        redirect: 'if_required'
                    });
                })
                .then(finishStripePayment)
                .catch(handleStripeError);
        });

        expressCheckoutElement.mount(expressMount);
    }

    /**
     * Shared end of both confirm flows (card and wallet).
     *
     * @param {{error?: object, paymentIntent?: object}} result
     */
    function finishStripePayment(result) {
        if (result.error) {
            throw new Error(result.error.message || 'We couldn’t process that card.');
        }
        // requires_capture: authorized, captured once the order is saved;
        // succeeded: an intent made before manual capture.
        var status = result.paymentIntent && result.paymentIntent.status;
        if (status !== 'requires_capture' && status !== 'succeeded') {
            throw new Error('Your card payment was not completed. Please try again.');
        }
        intentInput.value = result.paymentIntent.id;
        form.requestSubmit();
    }

    function handleStripeError(err) {
        showError((err && err.message) || 'We couldn’t process that card. Please check the details and try again.');
        if (submitButton) {
            submitButton.disabled = false;
        }
    }

    Array.prototype.forEach.call(
        form.querySelectorAll('input[name="payment_method"]'),
        function (radio) {
            radio.addEventListener('change', function () {
                if (isStripeChecked()) {
                    ensureElements().catch(function (err) {
                        showError((err && err.message) || 'Credit card form failed to load.');
                    });
                }
            });
        }
    );
    // Eager: the wallet buttons must be live from first paint.
    ensureElements().catch(function (err) {
        showError((err && err.message) || 'Credit card form failed to load.');
    });

    form.addEventListener('submit', function (event) {
        if (!isStripeChecked()) {
            return;
        }

        // Second pass: requestSubmit() below re-enters this handler after confirming.
        if (intentInput.value) {
            return;
        }

        event.preventDefault();
        clearError();
        if (submitButton) {
            submitButton.disabled = true;
        }

        ensureElements()
            .then(function () {
                return elements.submit();
            })
            .then(function (submitResult) {
                if (submitResult && submitResult.error) {
                    throw submitResult.error;
                }
                // Sync the intent amount to the live quote total right before confirming.
                return syncIntent();
            })
            .then(function () {
                return stripe.confirmPayment({
                    elements: elements,
                    confirmParams: { return_url: window.location.href },
                    redirect: 'if_required'
                });
            })
            .then(finishStripePayment)
            .catch(handleStripeError);
    });
})();
