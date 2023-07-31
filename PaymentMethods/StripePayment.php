<?php

use FluentCart\App\Modules\PaymentMethods\BasePaymentMethod;
use Stripe\Stripe;

use FluentCart\App\Vite;

class StripePayment extends BasePaymentMethod
{

    const BRAND_COLOR = '#136196';
    public function __construct()
    {
        require_once('StripeSettings.php');
        require_once('StripeApi.php');

        parent::__construct(
            __('Stripe Payment', 'fluent-cart'),
            'stripe_payment',
            self::BRAND_COLOR
        );

        add_action('fluent-cart/before_render_payment_method_' . $this->slug, [$this, 'loadCheckoutJs'], 10, 1);
    }

    public function makePayment($orderItem)
    {
        $hash = $this->resolveOrderHash($orderItem);
        $apiKey = (new StripeSettings($this->slug))->getApiKey();
        $customer = $orderItem->customer;
        $stripeSetting = $this->getSettings();

        $paymentArgs = array(
            'payment_method_type' => ['card'],
            'client_reference_id' => $hash,
            'items' => $orderItem->items,
            'amount' => (int) round($this->getPayableAmount($orderItem) * 100),
            'currency' => strtolower($customer->currency),
            'description' => "Payment for Order",
            'customer_email' => $customer->email,
            'success_url' => $this->getSuccessUrl($orderItem),
        );

        $invoiceResponse = (new StripeApi())->makeApiCall('invoices', $paymentArgs, $apiKey, 'POST');

        if ($stripeSetting['checkout_mode'] === 'modal') {
            if ($invoiceResponse) {
                $this->ShowModal($invoiceResponse, $stripeSetting['checkout_mode']);
            } else {
                if ($invoiceResponse) {
                    try {
                        wp_send_json_success(
                            [
                                'status' => 'success',
                                'message' => __('Order has been placed successfully', 'fluent-cart'),
                                'data' => $orderItem,
                                'redirect_to' => $invoiceResponse['url']
                            ],
                            200
                        );
                    } catch (\Exception $e) {
                        wp_send_json_error([
                            'status' => 'failed',
                            'message' => $e->getMessage()
                        ], 423);
                    }
                }
            }
        } else {
            if ($invoiceResponse) {
                try {
                    wp_send_json_success(
                        [
                            'status' => 'success',
                            'message' => __('Order has been placed successfully', 'fluent-cart'),
                            'data' => $orderItem,
                            'redirect_to' => $invoiceResponse['url']
                        ],
                        200
                    );
                } catch (\Exception $e) {
                    wp_send_json_error([
                        'status' => 'failed',
                        'message' => $e->getMessage()
                    ], 423);
                }
            }
        }
    }

    public function renderDescription()
    {
        echo '<p>' . esc_html__('Pay with cash upon delivery', 'fc') . '</p>';
    }

    public function isEnabled()
    {
        return true;
    }

    public function getLogo()
    {
        return  "https://upload.wikimedia.org/wikipedia/commons/thumb/b/ba/Stripe_Logo%2C_revised_2016.svg/2560px-Stripe_Logo%2C_revised_2016.svg.png";
    }

    public function getDescription()
    {
        return esc_html__('Pay with cash upon delivery', 'fc');
    }

    public function getSettings()
    {
        return ((new StripeSettings($this->slug))->get());
    }

    public function fields()
    {
        return array(
            'is_active' => array(
                'value' => 'no',
                'label' => __('Enable Stripe payment', 'fluent-cart'),
                'type' => 'enable'
            ),
            'payment_mode' => array(
                'value' => 'test',
                'label' => __('Payment Mode', 'fluent-cart'),
                'options' => array(
                    'test' => __('Test Mode', 'fluent-cart'),
                    'live' => __('Live Mode', 'fluent-cart')
                ),
                'type' => 'radio'
            ),
            'live_secret_key' => array(
                'value' => '',
                'label' => __('Live secret key', 'fluent-cart'),
                'type' => 'text'
            ),
            'test_secret_key' => array(
                'value' => '',
                'label' => __('Test secret key', 'fluent-cart'),
                'type' => 'text'
            ),
            'checkout_mode' => array(
                'value' => '',
                'label' => __('Checkout Mode', 'fluent-cart'),
                'options' => array(
                    'modal' => __('Modal Mode', 'fluent-cart'),
                    'hosted' => __('Hosted Mode', 'fluent-cart')
                ),
                'type' => 'radio'
            ),
            'webhook_desc' => array(
                'value' => "<h3>Stripe Webhook (For Subscription Payments) </h3> <p>If you use Stripe for recurring payments please set the notification URL in Stripe as bellow:<br/> <p><b>Webhook URL: </b><br/><code> " . site_url('?fct_payment_listener=1&method=stripe_payment') . "</code></p> <br/> you must configure your Stripe webhooks. Visit your <a href='https://stripe.com/docs/webhooks' target='_blank' rel='noopener'>account dashboard</a> to configure them. If you don't setup the IPN notification then it will still work for single payments but recurring payments will not be marked as paid for paypal subscription payments.</div>",
                'label' => __('Webhook URL', 'wp-payment-form'),
                'type' => 'html_attr'
            ),
        );
    }

    public function webHookPaymentMethodName()
    {
        return $this->slug;
    }

    public function onPaymentEventTriggered()
    {
        $data = (new StripeApi())->verifyIPN();

        if (!$data) {
            error_log('invalid data');
            return;
        }
        $this->loadCheckoutJs($my_data);
        $checkoutSessionId = $data->data->object->id;
        $orderHash = $data->data->object->client_reference_id;
        $apiKey = (new StripeSettings($this->slug))->getApiKey();
        $invoice = (new StripeApi())->getInvoice($checkoutSessionId, $apiKey);

        if (!$invoice || is_wp_error($invoice)) {
            error_log('invoice not found');
            return;
        }
        return $this->updateOrderStatusByHash($orderHash);
    }

    public function loadCheckoutJs($my_data)
    {
        wp_enqueue_script('fluent-cart-checkout-sdk-' . $this->slug, 'https://js.stripe.com/v3/', [], null, false);
        Vite::enqueueScript('fluent-cart-checkout-handler-' . $this->slug, 'public/payment-methods/stripe-checkout.js', ['fluent-cart-checkout-sdk-' . $this->slug], false);
    }

    public function ShowModal($invoiceResponse)
    {
        $responseData = [
            'nextAction'       => 'stripe',
            'actionName'       => 'custom',
            'buttonState'      => 'hide',
            'invoice_response' => $invoiceResponse,
            'message_to_show'  => __('Payment Modal is opening, Please complete the payment', 'fluent-cart'),
        ];
        wp_send_json_success($responseData, 200);
    }

    public function render($method)
    {
        echo '<div>
                <img class="!w-full !h-full !box-border" src="' . $this->getLogo() . '"alt="' . $this->title . '"/>
                <div class="paypal-button-wrapper">
                </div>
            </div>';
    }
}
