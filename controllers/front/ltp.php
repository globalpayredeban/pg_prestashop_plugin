<?php
include_once('utils.php');

class Globalpay_PaymentLtpModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        if (!$this->module->active) {
            $this->returnJson(['success' => false, 'error' => 'Module inactive']);
        }
        if (!Validate::isLoadedObject($this->context->customer)) {
            $this->returnJson(['success' => false, 'error' => 'Not authenticated']);
        }
    }

    public function postProcess()
    {
        $cart = $this->context->cart;
        $customer = $this->context->customer;

        if (!Validate::isLoadedObject($cart) || (int) $cart->id_customer !== (int) $customer->id) {
            $this->returnJson(['success' => false, 'error' => 'Invalid cart']);
        }
        if (!$this->isValidFrontSignature($cart, $customer)) {
            $this->returnJson(['success' => false, 'error' => 'Invalid request signature']);
        }

        $products = $cart->getProducts();
        $order_products = [];
        foreach ($products as $product) {
            $order_products[] = $product['cart_quantity'] . ' X ' . $product['name'];
        }
        $order_description = implode(', ', $order_products);
        if (strlen($order_description) > 240) {
            $order_description = substr($order_description, 0, 240);
        }

        $amountTaxData = Globalpay_PaymentUtils::getCartAmountAndVat($cart);
        $total = (float) $amountTaxData['total'];
        $vat = (float) $amountTaxData['vat'];
        $currency = Currency::getIsoCodeById($cart->id_currency);
        $environment = $this->mapEnvironment((string) Configuration::get('environment'));
        $phone = $this->getCustomerPhone($cart);
        $billing = $this->getBillingAddress($cart, $customer);

        // Create the PS order before redirecting — LTP is async (like bank transfer),
        // the order must exist regardless of payment outcome.
        $this->module->validateOrder(
            $cart->id,
            Configuration::get('PS_OS_PREPARATION'),
            $total,
            $this->module->displayName,
            null,
            [],
            $this->context->currency->id,
            false,
            $customer->secure_key
        );

        $order_id = (int) $this->module->currentOrder;
        if ($order_id <= 0) {
            $this->returnJson(['success' => false, 'error' => 'Order creation failed']);
        }

        $order = new Order($order_id);
        Globalpay_PaymentUtils::markOrderPaymentFlow($order, 'ltp');

        $base_confirm_url = $this->context->link->getPageLink(
            'order-confirmation',
            true,
            null,
            [
                'id_cart'   => (int) $cart->id,
                'id_module' => (int) $this->module->id,
                'id_order'  => $order_id,
                'key'       => $customer->secure_key,
            ]
        );

        $ltp_response = $this->callLtpInitOrder(
            $environment,
            (string) $customer->id,
            $customer->email,
            $customer->firstname,
            $customer->lastname,
            $phone,
            (string) $cart->id,
            $order_description,
            $total,
            $vat,
            $currency,
            $billing,
            (int) Configuration::get('ltp_expiration_days'),
            $base_confirm_url
        );

        if (empty($ltp_response['success'])) {
            // Order already created; mark it as error so merchant knows
            $history = new OrderHistory();
            $history->id_order = $order_id;
            $history->changeIdOrderState((int) Configuration::get('PS_OS_ERROR'), $order_id);
            $history->save();
            $this->returnJson(['success' => false, 'error' => $ltp_response['detail'] ?? 'LTP init failed']);
        }

        $ltp_order_id = $ltp_response['data']['order']['id'] ?? '';
        $payment_url  = $ltp_response['data']['payment']['payment_url'];

        // Store the LTP transaction id on the order payment record
        $collection = OrderPayment::getByOrderReference($order->reference);
        foreach ($collection as $order_payment) {
            if ($order_payment->payment_method == GP_FLAVOR . ' Prestashop Plugin') {
                $order_payment->transaction_id = $ltp_order_id;
                $order_payment->save();
            }
        }

        $this->returnJson(['success' => true, 'payment_url' => $payment_url]);
    }

    private function callLtpInitOrder(
        string $environment,
        string $user_id,
        string $user_email,
        string $user_name,
        string $user_last_name,
        ?string $user_phone,
        string $dev_reference,
        string $description,
        float $amount,
        float $vat,
        string $currency,
        array $billing_address,
        int $expiration_days,
        string $return_url
    ): array {
        $app_code_server = Configuration::get('app_code_server');
        $app_key_server = Configuration::get('app_key_server');

        $timestamp = (string) time();
        $uniq_token_hash = hash('sha256', $app_key_server . $timestamp);
        $auth_token = base64_encode($app_code_server . ';' . $timestamp . ';' . $uniq_token_hash);

        $ltp_url = ($environment === 'stg')
            ? 'https://noccapi-stg.' . GP_FLAVOR_DOMAIN . '/linktopay/init_order/'
            : 'https://noccapi.' . GP_FLAVOR_DOMAIN . '/linktopay/init_order/';

        $order_data = [
            'dev_reference' => $dev_reference,
            'description' => $description,
            'amount' => $amount,
            'installments_type' => -1,
            'currency' => $currency,
        ];

        if ($vat > 0) {
            $taxable_amount = round($amount - $vat, 2);
            $order_data['vat'] = $vat;
            $order_data['taxable_amount'] = $taxable_amount;
            $order_data['tax_percentage'] = ($taxable_amount > 0)
                ? round(($vat / $taxable_amount) * 100, 2)
                : 0.0;
        }

        $user = [
                'id' => $user_id,
                'email' => $user_email,
                'name' => $user_name,
                'last_name' => $user_last_name,
        ];

        if ($user_phone !== null && $user_phone !== '') {
            $user['phone'] = $user_phone;
        }

        $payload = json_encode([
            'user' => $user,
            'order' => $order_data,
            'billing_address' => $billing_address,
            'configuration' => [
                'partial_payment' => false,
                'expiration_days' => $expiration_days,
                'allowed_payment_methods' => ['All'],
                'success_url' => $return_url . '&ltp_status=success',
                'failure_url' => $return_url . '&ltp_status=failed',
                'pending_url' => $return_url . '&ltp_status=pending',
                'review_url' => $return_url . '&ltp_status=review',
            ],
        ]);

        $ch = curl_init($ltp_url);
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
            PrestaShopLogger::addLog('Globalpay linktopay cURL error: ' . $curl_error, 3);
            return ['success' => false, 'error' => 'LinkToPay communication failed'];
        }

        if ($http_code >= 400) {
            PrestaShopLogger::addLog('Globalpay linktopay failed. HTTP ' . $http_code . ' Response: ' . $response, 3);
        }

        return json_decode($response, true) ?? ['success' => false, 'error' => 'Invalid gateway response'];
    }

    private function getCustomerPhone(Cart $cart): ?string
    {
        $deliveryAddress = new Address((int) $cart->id_address_delivery);
        if (Validate::isLoadedObject($deliveryAddress)) {
            $phone = $this->normalizeCustomerPhone((string) $deliveryAddress->phone_mobile);
            if ($phone !== '') {
                return $phone;
            }
            $phone = $this->normalizeCustomerPhone((string) $deliveryAddress->phone);
            if ($phone !== '') {
                return $phone;
            }
        }

        $invoiceAddress = new Address((int) $cart->id_address_invoice);
        if (Validate::isLoadedObject($invoiceAddress)) {
            $phone = $this->normalizeCustomerPhone((string) $invoiceAddress->phone_mobile);
            if ($phone !== '') {
                return $phone;
            }
            $phone = $this->normalizeCustomerPhone((string) $invoiceAddress->phone);
            if ($phone !== '') {
                return $phone;
            }
        }

        return null;
    }

    private function normalizeCustomerPhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $pattern = '/^\d{1,3}-\d{7,15}$|^\+/';
        if (preg_match($pattern, $phone) === 1) {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === null || $digits === '') {
            return '';
        }

        $normalized = '+' . $digits;
        if (preg_match($pattern, $normalized) === 1) {
            return $normalized;
        }

        return '';
    }

    private function getBillingAddress(Cart $cart, Customer $customer): array
    {
        $billingAddress = new Address((int) $cart->id_address_invoice);
        if (!Validate::isLoadedObject($billingAddress)) {
            $billingAddress = new Address((int) $cart->id_address_delivery);
        }

        $countryIso = '';
        if (Validate::isLoadedObject($billingAddress)) {
            $country = new Country((int) $billingAddress->id_country);
            $countryIso = Globalpay_PaymentUtils::get_convert_country((string) $country->iso_code);
        }

        $stateIso = '';
        if (Validate::isLoadedObject($billingAddress) && (int) $billingAddress->id_state > 0) {
            $state = new State((int) $billingAddress->id_state);
            $stateIso = Globalpay_PaymentUtils::validate_state((string) $state->iso_code);
        }

        $street = Validate::isLoadedObject($billingAddress) ? trim((string) $billingAddress->address1) : '';
        $city = Validate::isLoadedObject($billingAddress) ? trim((string) $billingAddress->city) : '';
        $zip = Validate::isLoadedObject($billingAddress) ? trim((string) $billingAddress->postcode) : '';
        $additionalInfo = Validate::isLoadedObject($billingAddress) ? trim((string) $billingAddress->address2) : '';
        $firstName = Validate::isLoadedObject($billingAddress)
            ? trim((string) $billingAddress->firstname)
            : trim((string) $customer->firstname);
        $lastName = Validate::isLoadedObject($billingAddress)
            ? trim((string) $billingAddress->lastname)
            : trim((string) $customer->lastname);

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'street' => $street,
            'city' => substr($city, 0, 50),
            'state' => $stateIso,
            'district' => '',
            'zip' => substr($zip, 0, 50),
            'house_number' => $this->extractHouseNumber($street),
            'country' => $countryIso,
            'additional_address_info' => $additionalInfo,
        ];
    }

    private function extractHouseNumber(string $street): string
    {
        if (preg_match('/^\s*([0-9A-Za-z\-]+)/', $street, $matches)) {
            return trim($matches[1]);
        }

        return '';
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

    private function returnJson(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

if (!class_exists('PG_Prestashop_PluginLtpModuleFrontController', false)) {
    class_alias('Globalpay_PaymentLtpModuleFrontController', 'PG_Prestashop_PluginLtpModuleFrontController');
}
