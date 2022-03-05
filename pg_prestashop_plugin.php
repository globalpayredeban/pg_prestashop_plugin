

<?php
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

include_once(_PS_MODULE_DIR_.'pg_prestashop_plugin/classes/WebserviceSpecificManagementGlobalpayWebhook.php');

const FLAVOR = 'Globalpay';
const FLAVOR_DOMAIN = 'globalpay.com.co';
const REFUND_PATH = '/v2/transaction/refund/';

/**
 * PG_Prestashop_Plugin - A Payment Module for PrestaShop 1.7.7
 * @author Globalpay Development <dev@globalpay.com.co>
 * @license http://opensource.org/licenses/afl-3.0.php
 * @method l(string $string), Prestashop Language Method
 */
class PG_Prestashop_Plugin extends PaymentModule
{
    public function __construct()
    {
        $this->name                   = 'pg_prestashop_plugin';
        $this->tab                    = 'payments_gateways';
        $this->version                = '2.1.0';
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

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('header')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('actionProductCancel')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('addWebserviceResources');
    }

    public function uninstall(): bool
    {
        return parent::uninstall();
    }

    public function getContent(): string
    {
        $output = null;
        $error_messages = [];

        if (Tools::isSubmit('submit'.$this->name)) {
            $environment = strval(Tools::getValue('environment'));
            if (
                !$environment ||
                empty($environment) ||
                !Validate::isGenericName($environment)
            ) {
                array_push($error_messages, $this->l('Invalid Environment Configuration Value'));
            } else {
                Configuration::updateValue('environment', $environment);
            }

            $app_code_client = strval(Tools::getValue('app_code_client'));
            if (
                !$app_code_client ||
                empty($app_code_client) ||
                !Validate::isGenericName($app_code_client)
            ) {
                array_push($error_messages, $this->l('Invalid App Code Client Configuration Value'));
            } else {
                Configuration::updateValue('app_code_client', $app_code_client);
            }

            $app_key_client = strval(Tools::getValue('app_key_client'));
            if (
                !$app_key_client ||
                empty($app_key_client) ||
                !Validate::isGenericName($app_key_client)
            ) {
                array_push($error_messages, $this->l('Invalid App Key Client Configuration Value'));
            } else {
                Configuration::updateValue('app_key_client', $app_key_client);
            }

            $app_code_server = strval(Tools::getValue('app_code_server'));
            if (
                !$app_code_server ||
                empty($app_code_server) ||
                !Validate::isGenericName($app_code_server)
            ) {
                array_push($error_messages, $this->l('Invalid App Code Server Configuration Value'));
            } else {
                Configuration::updateValue('app_code_server', $app_code_server);
            }

            $app_key_server = strval(Tools::getValue('app_key_server'));
            if (
                !$app_key_server ||
                empty($app_key_server) ||
                !Validate::isGenericName($app_key_server)
            ) {
                array_push($error_messages, $this->l('Invalid App Key Server Configuration Value'));
            } else {
                Configuration::updateValue('app_key_server', $app_key_server);
            }

            $checkout_language = strval(Tools::getValue('checkout_language'));
            if (
                !$checkout_language ||
                empty($checkout_language) ||
                !Validate::isGenericName($checkout_language)
            ) {
                array_push($error_messages, $this->l('Invalid Checkout Language Configuration Value'));
            } else {
                Configuration::updateValue('checkout_language', $checkout_language);
            }

            $enable_card = strval(Tools::getValue('enable_card'));
            Configuration::updateValue('enable_card', $enable_card);

            $enable_ltp = strval(Tools::getValue('enable_ltp'));
            Configuration::updateValue('enable_ltp', $enable_ltp);

            if (!$enable_card && !$enable_ltp) {
                array_push($error_messages, $this->l('You must select at least one payment method.'));
            }

            $card_button_text = strval(Tools::getValue('card_button_text'));
            if (!$card_button_text ||
                empty($card_button_text) ||
                !Validate::isGenericName($card_button_text)
            ) {
                $card_button_text = $this->l("Pay With Card");
            }
            Configuration::updateValue('card_button_text', $card_button_text);

            $ltp_button_text = strval(Tools::getValue('ltp_button_text'));
            if (!$ltp_button_text ||
                empty($ltp_button_text) ||
                !Validate::isGenericName($ltp_button_text)
            ) {
                $ltp_button_text = $this->l("Pay With LinkToPay");
            }
            Configuration::updateValue('ltp_button_text', $ltp_button_text);

            $ltp_expiration_days = intval(Tools::getValue('ltp_expiration_days'));
            if (
                !$ltp_expiration_days ||
                empty($ltp_expiration_days) ||
                !Validate::isGenericName($ltp_expiration_days) ||
                !is_int($ltp_expiration_days)
            ) {
                array_push($error_messages, $this->l('Invalid LinkToPay Expiration Days Configuration Value'));
            } else {
                Configuration::updateValue('ltp_expiration_days', $ltp_expiration_days);
            }

            $enable_installments = strval(Tools::getValue('enable_installments'));
            Configuration::updateValue('enable_installments', $enable_installments);

            if (!$error_messages) {
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            } else {
                foreach ($error_messages as $error_message) {
                    $output .= $this->displayError($this->l($error_message));
                }
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
                'title' => $this->l('Payment Gateway Configurations: ').FLAVOR,
            ],
            'input' => [
                [
                    'type'      => 'select',
                    'label'     => $this->l('Environment:'),
                    'desc'      => $this->l('Payment Gateway Environment'),
                    'name'      => 'environment',
                    'required'  => true,
                    'options'   => [
                        'query' => [
                            [
                                'id_option' => 1,
                                'name' => $this->l('Test'),
                            ],
                            [
                                'id_option' => 2,
                                'name' => $this->l('Production'),
                            ],
                        ],
                        'id'    => 'id_option',
                        'name'  => 'name',
                    ]
                ],
                [
                    'type'     => 'text',
                    'label'    => $this->l('App Code Client:'),
                    'desc'     => $this->l('Unique commerce identifier in ').FLAVOR,
                    'name'     => 'app_code_client',
                    'required' => true
                ],
                [
                    'type'     => 'text',
                    'label'    => $this->l('App Key Client:'),
                    'desc'     => $this->l('Key used to encrypt communication with ').FLAVOR,
                    'name'     => 'app_key_client',
                    'required' => true
                ],
                [
                    'type'     => 'text',
                    'label'    => $this->l('App Code Server:'),
                    'desc'     => $this->l('Unique commerce identifier to perform admin actions on ').FLAVOR,
                    'name'     => 'app_code_server',
                    'required' => true
                ],
                [
                    'type'     => 'text',
                    'label'    => $this->l('App Key Server:'),
                    'desc'     => $this->l('Key used to encrypt admin communication with ').FLAVOR,
                    'name'     => 'app_key_server',
                    'required' => true
                ],
                [
                    'type'      => 'select',
                    'label'     => $this->l('Checkout Language:'),
                    'desc'      => $this->l('User\'s preferred language for checkout window. English will be used by default.'),
                    'name'      => 'checkout_language',
                    'required'  => true,
                    'options'   => [
                        'query' => [
                            [
                                'id_option' => 1,
                                'name'      => 'EN',
                            ],
                            [
                                'id_option' => 2,
                                'name'      => 'ES',
                            ],
                            [
                                'id_option' => 3,
                                'name'      => 'PT',
                            ],
                        ],
                        'id'   => 'id_option',
                        'name' => 'name',
                    ]
                ],
                [
                    'type'      => 'select',
                    'label'     => $this->l('Enable Card Payment:'),
                    'desc'      => $this->l('If selected, card can be used to pay.'),
                    'name'      => 'enable_card',
                    'required'  => true,
                    'options'   => [
                        'query' => [
                            [
                                'id_option' => 0,
                                'name'      => 'Disabled',
                            ],
                            [
                                'id_option' => 1,
                                'name'      => 'Enabled',
                            ],
                        ],
                        'id'    => 'id_option',
                        'name'  => 'name',
                    ]
                ],
                [
                    'type'      => 'select',
                    'label'     => $this->l('Enable LinkToPay:'),
                    'desc'      => $this->l('If selected, LinkToPay can be used to pay.'),
                    'name'      => 'enable_ltp',
                    'required'  => true,
                    'options'   => [
                        'query' => [
                            [
                                'id_option' => 0,
                                'name'      => 'Disabled',
                            ],
                            [
                                'id_option' => 1,
                                'name'      => 'Enabled',
                            ],
                        ],
                        'id'    => 'id_option',
                        'name'  => 'name',
                    ]
                ],
                [
                    'type'     => 'text',
                    'label'    => $this->l('Card Button Text:'),
                    'desc'     => $this->l('This controls the text that the user sees in the card payment button. Pay With Card is used by default.'),
                    'name'     => 'card_button_text',
                    'required' => false,
                ],
                [
                    'type'     => 'text',
                    'label'    => $this->l('LinkToPay Button Text:'),
                    'desc'     => $this->l('This controls the text that the user sees in the LinkToPay payment button. Pay With LinkToPay is used by default.'),
                    'name'     => 'ltp_button_text',
                    'required' => false,
                ],
                [
                    'type'     => 'text',
                    'label'    => $this->l('LinkToPay Expiration Days:'),
                    'desc'     => $this->l('This value controls the number of days that the generated LinkToPay will be available to pay.'),
                    'name'     => 'ltp_expiration_days',
                    'required' => true,
                ],
                [
                    'type'      => 'select',
                    'label'     => $this->l('Allow Installments:'),
                    'desc'      => $this->l('If selected, the installments options will be showed on the payment screen (Only on card payment).'),
                    'name'      => 'enable_installments',
                    'required'  => true,
                    'options'   => [
                        'query' => [
                            [
                                'id_option' => 0,
                                'name'      => 'Disabled',
                            ],
                            [
                                'id_option' => 1,
                                'name'      => 'Enabled',
                            ],
                        ],
                        'id'    => 'id_option',
                        'name'  => 'name',
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
        $helper->module          = $this;
        $helper->name_controller = $this->name;
        $helper->token           = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex    = AdminController::$currentIndex.'&configure='.$this->name;

        // Language
        $helper->default_form_language    = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        // Title and toolbar
        $helper->title          = $this->displayName;
        $helper->show_toolbar   = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action  = 'submit'.$this->name;
        $helper->toolbar_btn    = [
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
        $helper->fields_value['app_code_client']     = Tools::getValue('app_code_client', Configuration::get('app_code_client'));
        $helper->fields_value['app_key_client']      = Tools::getValue('app_key_client', Configuration::get('app_key_client'));
        $helper->fields_value['app_code_server']     = Tools::getValue('app_code_server', Configuration::get('app_code_server'));
        $helper->fields_value['app_key_server']      = Tools::getValue('app_key_server', Configuration::get('app_key_server'));
        $helper->fields_value['checkout_language']   = Tools::getValue('checkout_language', Configuration::get('checkout_language'));
        $helper->fields_value['environment']         = Tools::getValue('environment', Configuration::get('environment'));
        $helper->fields_value['enable_card']         = Tools::getValue('enable_card',  Configuration::get('enable_card'));
        $helper->fields_value['enable_ltp']          = Tools::getValue('enable_ltp',  Configuration::get('enable_ltp'));
        $helper->fields_value['ltp_button_text']     = Tools::getValue('ltp_button_text', Configuration::get('ltp_button_text'));
        $helper->fields_value['card_button_text']    = Tools::getValue('card_button_text', Configuration::get('card_button_text'));
        $helper->fields_value['ltp_expiration_days'] = intval(Tools::getValue('ltp_expiration_days', Configuration::get('ltp_expiration_days')));
        $helper->fields_value['enable_installments'] = Tools::getValue('enable_installments', Configuration::get('enable_installments'));

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
            $app_key_server  = Configuration::get('app_key_server');
            $refund_data = [
                "transaction" => ["id" => $transaction_id],
                "order" => ["amount" => round($amount_to_refund, 2, PHP_ROUND_HALF_DOWN)]
            ];
            $payload = json_encode($refund_data);

            $timestamp         = (string)time();
            $uniq_token_string = $app_key_server . $timestamp;
            $uniq_token_hash   = hash('sha256', $uniq_token_string);
            $auth_token        = base64_encode($app_code_server . ';' . $timestamp . ';' . $uniq_token_hash);

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
                $tab          = Tools::getValue('tab');
                $currentIndex = __PS_BASE_URI__ . substr($_SERVER['SCRIPT_NAME'], strlen(__PS_BASE_URI__)) . ($tab ? '?tab=' . $tab : '');
                $token        = Tools::getAdminTokenLite($tab);
                // TODO: Este reedirect no recarga la página actual, no c que pasa, parece tema del currentIndex
                Tools::redirectLink($currentIndex . '&id_order=' . $order->id . '&vieworder&conf=1&token=' . $token);

            } else {
                // TODO: Agregar a un helper? change_order_history() o algo así
                $history           = new OrderHistory();
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

    public function hookAddWebserviceResources()
    {
        $webhook_name = strtolower(FLAVOR.'webhook');
        return array(
            $webhook_name => array(
                'description'         => FLAVOR.' Webhook Management.',
                'specific_management' => true
            )
        );
    }
}