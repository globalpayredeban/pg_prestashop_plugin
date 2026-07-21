<?php
include_once('utils.php');

class Globalpay_PaymentPaymentModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        if (!$this->module->active) {
            Tools::redirect($this->context->link->getPageLink('order'));
        }
        $customer = $this->context->customer;
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink('order'));
        }
    }

    /**
     * @throws PrestaShopException
     */
    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;
        $customer = $this->context->customer;

        $checkout_language = $this->mapCheckoutLanguage((string) Configuration::get('checkout_language'));
        $environment = $this->mapEnvironment((string) Configuration::get('environment'));
        $pg_sig = Globalpay_PaymentUtils::buildFrontSecuritySignature($cart, $customer);

        $this->context->smarty->assign([
            'checkout_language' => $checkout_language,
            'environment' => $environment,
            'card_init_url' => $this->context->link->getModuleLink($this->module->name, 'payment', [
                'action' => 'initCardReference',
                'pg_sig' => $pg_sig,
            ], true),
            'ltp_init_url' => $this->context->link->getModuleLink($this->module->name, 'ltp', [
                'pg_sig' => $pg_sig,
            ], true),
            'pg_sig' => $pg_sig,
            'products' => $cart->getProducts(),
            'card_button_text' => Configuration::get('card_button_text'),
            'ltp_button_text' => Configuration::get('ltp_button_text'),
            'enable_card' => Configuration::get('enable_card'),
            'enable_ltp' => Configuration::get('enable_ltp'),
        ]);

        $this->setTemplate('module:'.$this->module->name.'/views/templates/front/payment.tpl');
    }

    public function setMedia()
    {
        parent::setMedia();
        $this->registerJavascript(
            'jquery-3-6-0',
            'https://code.jquery.com/jquery-3.6.0.min.js',
            ['server' => 'remote', 'position' => 'head', 'priority' => 5]
        );
    }

    /**
     * @throws PrestaShopException
     */
    public function postProcess()
    {
        $action = Tools::getValue('action');
        if ($action === 'initCardReference') {
            $cart = $this->context->cart;
            $customer = $this->context->customer;
            if (
                !Validate::isLoadedObject($cart)
                || !Validate::isLoadedObject($customer)
                || !$this->isValidFrontSignature($cart, $customer)
            ) {
                $this->returnJson(['success' => false, 'error' => 'Invalid request signature']);
            }
            $this->initCardReference();
        }

        if (empty($_POST)) {
            return;
        }

        try {
            $cart = $this->context->cart;
            if (!Validate::isLoadedObject($cart)) {
                throw new Exception('Invalid cart');
            }

            $customer = new Customer($cart->id_customer);
            if (!Validate::isLoadedObject($customer)) {
                throw new Exception('Invalid customer');
            }

            if (!$this->isValidFrontSignature($cart, $customer)) {
                throw new Exception('Invalid request signature');
            }

            $gateway_amount = (float) Tools::getValue('amount');
            $amountTaxData = Globalpay_PaymentUtils::getCartAmountAndVat($cart);
            $cart_total = (float) $amountTaxData['total'];
            $payment_id = (string) Tools::getValue('id');
            $status_detail = (int) Tools::getValue('status_detail');

            if ($payment_id === '') {
                throw new Exception('Missing payment id');
            }
            if ($gateway_amount <= 0) {
                throw new Exception('Missing payment amount');
            }
            if (abs($gateway_amount - $cart_total) > 0.01) {
                throw new Exception('Payment amount mismatch');
            }

            if ($status_detail !== 3) {
                throw new Exception('Payment not approved');
            }

            $this->module->validateOrder(
                $cart->id,
                Configuration::get('PS_OS_PREPARATION'),
                $gateway_amount,
                $this->module->displayName,
                null,
                [],
                $this->context->currency->id,
                false,
                $customer->secure_key
            );

            if (!$this->module->currentOrder) {
                throw new Exception('Order creation failed');
            }

            $this->context->cookie->pg_payment_approved = '1';

            $this->assignPaymentId($payment_id);
            Globalpay_PaymentUtils::markOrderPaymentFlow(new Order((int) $this->module->currentOrder), 'card');

            $confirmUrl = 'index.php?controller=order-confirmation'
                . '&id_cart=' . (int) $cart->id
                . '&id_module=' . (int) $this->module->id
                . '&id_order=' . (int) $this->module->currentOrder
                . '&key=' . $customer->secure_key;

            Tools::redirect($confirmUrl);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Globalpay payment postProcess error: ' . $e->getMessage(), 3);
            $this->errors[] = $this->module->l('An error occurred while processing your payment.', 'payment');
            $this->redirectWithNotifications($this->context->link->getPageLink('order'));
        }
    }

    private function initCardReference()
    {
        try {
            $cart = $this->context->cart;
            if (!Validate::isLoadedObject($cart)) {
                $this->returnJson(['success' => false, 'error' => 'Invalid cart']);
            }

            $customer = $this->context->customer;
            if (!Validate::isLoadedObject($customer)) {
                $this->returnJson(['success' => false, 'error' => 'Not authenticated']);
            }

            $billing_address = new Address($cart->id_address_invoice);
            $address_delivery_country = new Country($billing_address->id_country);
            $iso_code = Globalpay_PaymentUtils::get_convert_country($address_delivery_country->iso_code);
            $address_delivery_state = new State($billing_address->id_state);
            $iso_code_state = Globalpay_PaymentUtils::validate_state($address_delivery_state->iso_code);

            $amountTaxData = Globalpay_PaymentUtils::getCartAmountAndVat($cart);
            $total = (float) $amountTaxData['total'];
            $vat = (float) $amountTaxData['vat'];
            $products = $cart->getProducts();

            $order_products = [];
            foreach ($products as $product) {
                $order_products[] = $product['cart_quantity'] . ' X ' . $product['name'];
            }
            $order_description = implode(', ', $order_products);
            if (strlen($order_description) > 240) {
                $order_description = substr($order_description, 0, 240);
            }

            $checkout_language = $this->mapCheckoutLanguage((string) Configuration::get('checkout_language'));
            $environment = $this->mapEnvironment((string) Configuration::get('environment'));
            $currency = Currency::getIsoCodeById($cart->id_currency);

            $billing = [
                'street' => $billing_address->address1,
                'city' => $billing_address->city,
                'country' => $iso_code,
                'state' => $iso_code_state,
                'zip' => $billing_address->postcode,
            ];

            $reference = $this->callInitReference(
                $environment,
                (string) $cart->id_customer,
                $customer->email,
                $total,
                $order_description,
                (string) $cart->id,
                $vat,
                $currency,
                $checkout_language,
                $billing
            );

            if (empty($reference)) {
                $this->returnJson(['success' => false, 'error' => 'Failed to get reference from gateway']);
            }

            $this->returnJson(['success' => true, 'reference' => $reference]);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Globalpay initCardReference error: ' . $e->getMessage(), 3);
            $this->returnJson(['success' => false, 'error' => 'An error occurred']);
        }
    }

    private function returnJson(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function callInitReference(
        string $environment,
        string $user_id,
        string $user_email,
        float $amount,
        string $description,
        string $dev_reference,
        float $vat,
        string $currency,
        string $locale,
        array $billing_address
    ): string {
        $app_code_server = Configuration::get('app_code_server');
        $app_key_server = Configuration::get('app_key_server');

        $timestamp = (string) time();
        $uniq_token_hash = hash('sha256', $app_key_server . $timestamp);
        $auth_token = base64_encode($app_code_server . ';' . $timestamp . ';' . $uniq_token_hash);

        $base_url = ($environment === 'stg')
            ? 'https://ccapi-stg.' . GP_FLAVOR_DOMAIN
            : 'https://ccapi.' . GP_FLAVOR_DOMAIN;

        $order_data = [
            'dev_reference' => $dev_reference,
            'description' => $description,
            'amount' => $amount,
            'currency' => $currency,
            'vat' => 0,
        ];

        if ($vat > 0) {
            $taxable_amount = round($amount - $vat, 2);
            $order_data['vat'] = $vat;
            $order_data['taxable_amount'] = $taxable_amount;
            $order_data['tax_percentage'] = ($taxable_amount > 0)
                ? round(($vat / $taxable_amount) * 100, 2)
                : 0.0;
        }

        $installments_type = (int) Configuration::get('installments_type');
        if ($installments_type !== -1) {
            $order_data['installments_type'] = $installments_type;
        }

        $payload = json_encode([
            'locale' => $locale,
            'user' => ['id' => $user_id, 'email' => $user_email],
            'order' => $order_data,
            'billing_address' => $billing_address,
        ]);

        $ch = curl_init($base_url . '/v2/transaction/init_reference/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Auth-Token: ' . $auth_token,
        ]);
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curl_error !== '') {
            PrestaShopLogger::addLog('Globalpay init_reference cURL error: ' . $curl_error, 3);
            return '';
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['reference'])) {
            PrestaShopLogger::addLog(
                'Globalpay init_reference failed. HTTP ' . $http_code . ' Response: ' . $response,
                3
            );
        }
        return $data['reference'] ?? '';
    }

    private function assignPaymentId(string $payment_id): void
    {
        $order = new Order($this->module->currentOrder);
        $collection = OrderPayment::getByOrderReference($order->reference);
        if (count($collection) > 0) {
            foreach ($collection as $order_payment) {
                if ($order_payment->payment_method == GP_FLAVOR . ' Prestashop Plugin') {
                    $order_payment->transaction_id = $payment_id;
                    $order_payment->update();
                }
            }
        }
    }

    private function mapCheckoutLanguage(string $checkout_language): string
    {
        return [1 => 'en', 2 => 'es', 3 => 'pt'][$checkout_language] ?? 'en';
    }

    private function mapEnvironment(string $environment): string
    {
        return [1 => 'stg', 2 => 'prod'][$environment] ?? 'stg';
    }

    private function isValidFrontSignature(Cart $cart, Customer $customer): bool
    {
        $signature = (string) Tools::getValue('pg_sig');
        return Globalpay_PaymentUtils::isValidFrontSecuritySignature($signature, $cart, $customer);
    }
}

if (!class_exists('PG_Prestashop_PluginPaymentModuleFrontController', false)) {
    class_alias('Globalpay_PaymentPaymentModuleFrontController', 'PG_Prestashop_PluginPaymentModuleFrontController');
}
