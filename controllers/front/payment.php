<?php
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
            'app_code'             => Configuration::get('app_code_client'),
            'app_key'              => Configuration::get('app_key_client'),
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
            2  => $this->module->l('Deferred with interest (Ecuador, México)', 'payment'),
            3  => $this->module->l('Deferred without interest (Ecuador, México)', 'payment'),
            9  => $this->module->l('Deferred without interest and months of grace (Ecuador, México)', 'payment'),
        ];
    }
}