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

    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;
        $customer = $this->context->customer;
        $total = (float)$cart->getOrderTotal(true, Cart::BOTH);
        $vat = (float)0.0;
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
        $environment = $this->mapEnvironment(Configuration::get('environment'));
        $this->context->smarty->assign([
            'app_code' => Configuration::get('app_code_client'),
            'app_key' => Configuration::get('app_key_client'),
            'checkout_language' => $checkout_language,
            'environment' => $environment,
            'user_id' =>  $cart->id_customer,
            'user_email' => $customer->email,
            'order_description' => $order_description,
            'order_amount' => $total,
            'order_vat' => $vat,
            'order_reference' => $cart->id,
            'products' => $products
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
            $amount = Tools::getValue('amount');
            $payment_id = Tools::getValue('id');
            $status = Tools::getValue('status');
            $total = (float)$amount;

            $cart = $this->context->cart;
            $customer = new Customer($cart->id_customer);

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

            $order = new Order($this->module->currentOrder);
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
            $this->context->smarty->assign([
                'pg_status' => $status,
                'payment_id' => $payment_id,
                'module_gtw' => $this->module->displayName
            ]);
            Tools::redirect('index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key);
        }
    }

    private function mapCheckoutLanguage($checkout_language)
    {
        return  [1 => 'es', 2 => 'en', 3 => 'pt',][$checkout_language];
    }

    private function mapEnvironment($environment)
    {
        return [1 => 'stg', 2 => 'prod',][$environment];
    }
}