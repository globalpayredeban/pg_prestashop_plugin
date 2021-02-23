

<?php
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

define('FLAVOR', 'Globalpay');
define('FLAVOR_DOMAIN', 'globalpay.com.co');
define('REFUND_PATH', '/v2/transaction/refund/');

/**
 * PG_Prestashop_Plugin - A Payment Module for PrestaShop 1.7.7
 * @author Globalpay Development <dev@globalpay.com.co>
 * @license http://opensource.org/licenses/afl-3.0.php
 */
class PG_Prestashop_Plugin extends PaymentModule
{
    public function __construct()
    {
        $this->name                   = 'pg_prestashop_plugin';
        $this->tab                    = 'payments_gateways';
        $this->version                = '1.0.0';
        $this->author                 = FLAVOR.$this->l(' Development');
        $this->currencies             = true;
        $this->currencies_mode        = 'radio';
        $this->bootstrap              = true;
        $this->displayName            = FLAVOR.' Prestashop Plugin';
        $this->description            = FLAVOR.$this->l(' Payment module for process card payments.');
        $this->confirmUninstall       = $this->l('Are you sure you want to uninstall the payment module by ').FLAVOR.'?';
        $this->ps_versions_compliancy = array('min' => '1.7.7.0', 'max' => _PS_VERSION_);

        parent::__construct();
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('header')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('actionProductCancel')
            && $this->registerHook('paymentOptions');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit'.$this->name)) {
            // TODO: Un Settings updated por cada campo? cambiar eso
            $app_code_client = strval(Tools::getValue('app_code_client'));
            if (
                !$app_code_client ||
                empty($app_code_client) ||
                !Validate::isGenericName($app_code_client)
            ) {
                $output .= $this->displayError($this->l('Invalid App Code Client Configuration Value'));
            } else {
                Configuration::updateValue('app_code_client', $app_code_client);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }

            $app_key_client = strval(Tools::getValue('app_key_client'));
            if (
                !$app_key_client ||
                empty($app_key_client) ||
                !Validate::isGenericName($app_key_client)
            ) {
                $output .= $this->displayError($this->l('Invalid App Key Client Configuration Value'));
            } else {
                Configuration::updateValue('app_key_client', $app_key_client);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }

            $app_code_server = strval(Tools::getValue('app_code_server'));
            if (
                !$app_code_server ||
                empty($app_code_server) ||
                !Validate::isGenericName($app_code_server)
            ) {
                $output .= $this->displayError($this->l('Invalid App Code Server Configuration Value'));
            } else {
                Configuration::updateValue('app_code_server', $app_code_server);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }

            $app_key_server = strval(Tools::getValue('app_key_server'));
            if (
                !$app_key_server ||
                empty($app_key_server) ||
                !Validate::isGenericName($app_key_server)
            ) {
                $output .= $this->displayError($this->l('Invalid App Key Server Configuration Value'));
            } else {
                Configuration::updateValue('app_key_server', $app_key_server);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }

            $checkout_language = strval(Tools::getValue('checkout_language'));
            if (
                !$checkout_language ||
                empty($checkout_language) ||
                !Validate::isGenericName($checkout_language)
            ) {
                $output .= $this->displayError($this->l('Invalid Checkout Language Configuration Value'));
            } else {
                Configuration::updateValue('checkout_language', $checkout_language);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }

            $environment = strval(Tools::getValue('environment'));
            if (
                !$environment ||
                empty($environment) ||
                !Validate::isGenericName($environment)
            ) {
                $output .= $this->displayError($this->l('Invalid Environment Configuration Value'));
            } else {
                Configuration::updateValue('environment', $environment);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        return $output.$this->displayForm();
    }

    public function displayForm()
    {
        // Get default language
        $defaultLang = (int)Configuration::get('PS_LANG_DEFAULT');

        // Init Fields form array
        $fieldsForm[0]['form'] = [
            'legend' => [
                'title' => FLAVOR.$this->l(' Payment Gateway Configurations'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('App Code Client:'),
                    'name' => 'app_code_client',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('App Key Client:'),
                    'name' => 'app_key_client',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('App Code Server:'),
                    'name' => 'app_code_server',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('App Key Server:'),
                    'name' => 'app_key_server',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Checkout Language:'),
                    'name' => 'checkout_language',
                    'required' => true,
                    'options' => [
                        'query' => $options = [
                            [
                                'id_option' => 1,
                                'name' => 'ES',
                            ],
                            [
                                'id_option' => 2,
                                'name' => 'EN',
                            ],
                            [
                                'id_option' => 3,
                                'name' => 'PT',
                            ],
                        ],
                        'id' => 'id_option',
                        'name' => 'name',
                    ]
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Environment:'),
                    'name' => 'environment',
                    'required' => true,
                    'options' => [
                        'query' => $options = [
                            [
                                'id_option' => 1,
                                'name' => 'STG',
                            ],
                            [
                                'id_option' => 2,
                                'name' => 'PROD',
                            ],
                        ],
                        'id' => 'id_option',
                        'name' => 'name',
                    ]
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        // Language
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                    '&token='.Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];

        // Load currents values
        $helper->fields_value['app_code_client'] = Tools::getValue('app_code_client', Configuration::get('app_code_client'));
        $helper->fields_value['app_key_client'] = Tools::getValue('app_key_client', Configuration::get('app_key_client'));
        $helper->fields_value['app_code_server'] = Tools::getValue('app_code_server', Configuration::get('app_code_server'));
        $helper->fields_value['app_key_server'] = Tools::getValue('app_key_server', Configuration::get('app_key_server'));
        $helper->fields_value['checkout_language'] = Tools::getValue('checkout_language', Configuration::get('checkout_language'));
        $helper->fields_value['environment'] = Tools::getValue('environment', Configuration::get('environment'));

        return $helper->generateForm($fieldsForm);
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        /**
         * Create a PaymentOption object containing the necessary data
         * to display this module in the checkout
         */

         
        $this->context->smarty->assign(array(
            'flavor' => FLAVOR,
        ));

        $newOption = new PaymentOption();
        $newOption->setModuleName($this->displayName)
                  ->setCallToActionText($this->displayName)
                  ->setAction($this->context->link->getModuleLink($this->name, 'payment'))
                  ->setAdditionalInformation($this->context->smarty->fetch('module:pg_prestashop_plugin/views/templates/front/payment_infos.tpl'));

        return [$newOption];
    }

    public function hookActionProductCancel(array $params)
    {
        if ($params['action'] === 1) {
            $amount_to_refund = 0;

            $cancel_product = $_POST['cancel_product'];
            $order = $params['order'];
            if (isset($cancel_product['shipping'])) {
                $amount_to_refund += (float)$order->total_shipping;
            }

            $keys_pop = ['_token', 'save', 'voucher_refund_type', 'voucher', 'credit_slip', 'shipping_amount', 'shipping'];
            foreach ($keys_pop as $key) {
                unset($cancel_product[$key]);
            }
            // TODO: Definir un poco mas claras las variables y nombres para saber de que tratan, esta lógica es algo confusa
            $selected = [];
            $quantity = [];
            foreach (array_keys($cancel_product) as $key) {
                if (strpos($key, 'selected') !== false) {
                    $id_order_detail = (string)explode('_', $key)[1];
                    $selected[$id_order_detail] = $cancel_product[$key];
                } else if (strpos($key, 'quantity') !== false) {
                    $id_order_detail = (string)explode('_', $key)[1];
                    $quantity[$id_order_detail] = $cancel_product[$key];
                }
            }

            $unit_prices = [];
            foreach ($selected as $key => $value) {
                if ($value) {
                    $order_detail = new OrderDetail((int)$key);
                    $unit_prices[$key] = (float)$order_detail->unit_price_tax_incl;
                }
            }
            foreach ($unit_prices as $key => $value) {
                $amount_to_refund += $quantity[$key] * $value;
            }

            $environment = Configuration::get('environment');
            $url = ($environment == 1) ? 'https://ccapi-stg.' . FLAVOR_DOMAIN . REFUND_PATH : 'https://ccapi.' . FLAVOR_DOMAIN . REFUND_PATH;

            // TODO: Crear un "get_payment_id()" para no copiar y pegar código para obtener el $transaction_id
            $collection = OrderPayment::getByOrderReference($order->reference);
            $transaction_id = "";
            if (count($collection) > 0) {
                foreach ($collection as $order_payment) {
                    if ($order_payment->payment_method == FLAVOR . ' Prestashop Plugin') {
                        $transaction_id = $order_payment->transaction_id;
                    }
                }
            }

            $app_code_server = Configuration::get('app_code_server');
            $app_key_server = Configuration::get('app_key_server');
            $refund_data = [
                "transaction" => ["id" => $transaction_id],
                "order" => ["amount" => round($amount_to_refund, 2, PHP_ROUND_HALF_DOWN)]
            ];
            $payload = json_encode($refund_data);

            $timestamp = (string)time();
            $uniq_token_string = $app_key_server . $timestamp;
            $uniq_token_hash = hash('sha256', $uniq_token_string);
            $auth_token = base64_encode($app_code_server . ';' . $timestamp . ';' . $uniq_token_hash);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, ($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type:application/json',
                'Auth-Token:' . $auth_token));
            $response = curl_exec($ch);
            curl_close($ch);
            $get_response = json_decode($response, true);
            if ($get_response['error'] || $get_response['status'] == 'failure') {
                $tab = Tools::getValue('tab');
                $currentIndex = __PS_BASE_URI__ . substr($_SERVER['SCRIPT_NAME'], strlen(__PS_BASE_URI__)) . ($tab ? '?tab=' . $tab : '');
                $token = Tools::getAdminTokenLite($tab);
                // TODO: Este reedirect no recarga la página actual, no c que pasa, parece tema del currentIndex
                Tools::redirectLink($currentIndex . '&id_order=' . $order->id . '&vieworder&conf=1&token=' . $token);

            } else {
                // TODO: Agregar a un helper? change_order_history() o algo así
                $history = new OrderHistory();
                $history->id_order = (int)$order->id;
                $history->changeIdOrderState(7, (int)($order->id));
                $history->save();
            }
        }
    }

    public function hookHeader()
    {
        $this->context->controller->registerStylesheet(
            'front-css',
            'modules/' . $this->name . '/views/css/main.css'
        );
    }


    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $transaction_id = '';
        $collection = OrderPayment::getByOrderReference($params['order']->reference);
        if (count($collection) > 0)
        {
            foreach ($collection as $order_payment)
            {
                $transaction_id = $order_payment->transaction_id;
            }
        }
        
        $this->context->smarty->assign(array(
            'payment_id' => $transaction_id,
            'module_gtw' => $this->displayName
        ));
        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }
}