<?php
include_once('utils.php');
class PG_Prestashop_PluginPaymentModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        if(!$this->module->active)
        {
            Tools::redirect($this->context->link->getPageLink('order'));
        }
        $customer = $this->context->customer;
        if(!Validate::isLoadedObject($customer))
        {
            Tools::redirect($this->context->link->getPageLink('order'));
        }
    }

    /**
     * @throws PrestaShopException
     * @throws Exception
     */
    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;
        $customer = $this->context->customer;
        $billing_address = new Address($cart->id_address_invoice);
        $address_delivery_country = new Country($billing_address->id_country);
        $iso_code = PG_Prestashop_Utils::get_convert_country($address_delivery_country->iso_code);
        $address_delivery_state = new State($billing_address->id_state);
        $iso_code_state = PG_Prestashop_Utils::validate_state($address_delivery_state->iso_code);
        $total = (float)$cart->getOrderTotal();
        $products = $cart->getProducts();
        $order_products = [];
        foreach ($products as $product)
            $order_products[] = $product['cart_quantity']." X ".$product['name'];
        $order_description = implode(", ", $order_products);
        if (strlen($order_description) > 240)
        {
            $order_description = substr($order_description,0,240);
        }
        $checkout_language = $this->mapCheckoutLanguage(Configuration::get('checkout_language'));
        $environment       = $this->mapEnvironment(Configuration::get('environment'));

        $this->context->smarty->assign([
            'app_code_client'      => Configuration::get('app_code_client'),
            'app_key_client'       => Configuration::get('app_key_client'),
            'app_code_server'      => Configuration::get('app_code_server'),
            'app_key_server'       => Configuration::get('app_key_server'),
            'checkout_language'    => $checkout_language,
            'environment'          => $environment,
            'ltp_url'              => $this->mapLinkToPayUrl($environment),
            'user_id'              => $cart->id_customer,
            'user_email'           => $customer->email,
            'order_description'    => $order_description,
            'order_amount'         => $total,
            'order_vat'            => 0.0,
            'order_reference'      => $cart->id,
            'products'             => $products,
            'user_firstname'       => $customer->firstname,
            'user_lastname'        => $customer->lastname,
            'currency'             => Currency::getIsoCodeById($cart->id_currency),
            'expiration_days'      => Configuration::get('ltp_expiration_days'),
            'order_url'            => Context::getContext()->shop->getBaseURL(true).'index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key,
            'ltp_button_text'      => Configuration::get('ltp_button_text'),
            'card_button_text'     => Configuration::get('card_button_text'),
            'enable_installments'  => Configuration::get('enable_installments'),
            'installments_options' => $this->getInstallmentsOptions(),
            'enable_card'          => Configuration::get('enable_card'),
            'enable_ltp'           => Configuration::get('enable_ltp'),
            'billing_address'  => [
                'street'               => $billing_address->address1,
                'city'                 => $billing_address->city,
                'country'              => $iso_code,
                'state'                => $iso_code_state,
                'zip'                  => $billing_address->postcode
            ]
        ]);

        $this->setTemplate('module:pg_prestashop_plugin/views/templates/front/payment.tpl');
    }

    public function setMedia()
    {
        parent::setMedia();
    }

    public function postProcess()
    {
        if (!empty($_POST))
        {
            $cart           = $this->context->cart;
            $customer       = new Customer($cart->id_customer);

            $total          = (float)Tools::getValue('amount');
            $payment_id     = Tools::getValue('id');
            $status         = Tools::getValue('status');
            $payment_method = Tools::getValue('payment_method');

            if ($payment_method == 'LinkToPay')
            {
                $this->module->validateOrder($cart->id, Configuration::get('PS_OS_BANKWIRE'), $total, $this->module->displayName, null, array(), $this->context->currency->id, false, $customer->secure_key);

                $this->assignPaymentId($payment_id);

                $payment_url    = Tools::getValue('payment_url');
                $this->context->smarty->assign([
                    'pg_status'      => 'pending',
                    'payment_id'     => Tools::getValue('id'),
                    'module_gtw'     => $this->module->displayName,
                    'payment_method' => $payment_method,
                    'payment_url'    => $payment_url
                ]);
                Tools::redirect($payment_url);
            }

            if ($status == 'success')
            {
                $this->module->validateOrder($cart->id, Configuration::get('PS_OS_PAYMENT'), $total, $this->module->displayName, null, array(), $this->context->currency->id, false, $customer->secure_key);
            }
            elseif ($status == 'pending')
            {
                $this->module->validateOrder($cart->id, Configuration::get('PS_OS_PREPARATION'), $total, $this->module->displayName, null, array(), $this->context->currency->id, false, $customer->secure_key);
            }
            else
            {
                $this->module->validateOrder($cart->id, Configuration::get('PS_OS_ERROR'), $total, $this->module->displayName, null, array(), $this->context->currency->id, false, $customer->secure_key);
            }

            $this->assignPaymentId($payment_id);

            $this->context->smarty->assign([
                'pg_status'      => $status,
                'payment_id'     => $payment_id,
                'module_gtw'     => $this->module->displayName,
                'payment_method' => $payment_method
            ]);
            Tools::redirect('index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key);
        }
    }

    private function assignPaymentId($payment_id) {
        $order      = new Order($this->module->currentOrder);
        $collection = OrderPayment::getByOrderReference($order->reference);
        if (count($collection) > 0)
        {
            foreach ($collection as $order_payment)
            {
                if ($order_payment->payment_method == FLAVOR . ' Prestashop Plugin')
                {
                    $order_payment->transaction_id = $payment_id;
                    $order_payment->update();
                }
            }
        }
    }

    private function mapCheckoutLanguage($checkout_language): string
    {
        return  [1 => 'en', 2 => 'es', 3 => 'pt',][$checkout_language];
    }

    private function mapEnvironment($environment): string
    {
        return [1 => 'stg', 2 => 'prod',][$environment];
    }

    private function mapLinkToPayUrl($environment): string
    {
        return [
            'stg' => 'https://noccapi-stg.'.FLAVOR_DOMAIN.'/linktopay/init_order/',
            'prod' => 'https://noccapi.'.FLAVOR_DOMAIN.'/linktopay/init_order/'
        ][$environment];
    }

    private function getInstallmentsOptions(): array
    {
        return [
            1  => $this->module->l('Habilitar Cuotas', 'payment'),
        ];
    }
}