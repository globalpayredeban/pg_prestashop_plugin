

<?php
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

include_once(__DIR__.'/controllers/front/utils.php');
include_once(__DIR__.'/classes/WebserviceSpecificManagementGlobalpayWebhook.php');

const GP_FLAVOR = 'Globalpay';
const GP_FLAVOR_DOMAIN = 'globalpay.com.co';
const GP_REFUND_PATH = '/v2/transaction/refund/';
const GP_WEBHOOK_RESOURCE_NAME = 'globalpaywebhook';
const GP_WEBHOOK_WS_CONFIG_KEY = 'PG_PRESTASHOP_PLUGIN_WEBHOOK_WS_KEY';
const GP_REFUND_GUARD_TABLE = 'globalpay_refund_guard';

/**
 * PG_Prestashop_Plugin - A Payment Module for PrestaShop 8.x / 9.x
 * @author Globalpay Development <dev@globalpay.com.co>
 * @license http://opensource.org/licenses/afl-3.0.php
 * @method l(string $string), Prestashop Language Method
 */
class Globalpay_Payment extends PaymentModule
{
    private static bool $refundRequestHandled = false;

    public function __construct()
    {
        $this->name                   = basename(__DIR__);
        $this->tab                    = 'payments_gateways';
        $this->version                = '3.0.1';
        $this->author                 = GP_FLAVOR.$this->l(' Development');
        $this->currencies             = true;
        $this->currencies_mode        = 'radio';
        $this->bootstrap              = true;
        $this->displayName            = GP_FLAVOR.' Prestashop Plugin';
        $this->description            = GP_FLAVOR.$this->l(' Payment module for process card payments.');
        $this->confirmUninstall       = $this->l('Are you sure you want to uninstall the payment module by ').GP_FLAVOR.'?';
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => _PS_VERSION_);

        parent::__construct();
    }

    public function install(): bool
    {
        $installed = parent::install()
            && $this->registerHook('header')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('actionProductCancel')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('addWebserviceResources');

        if ($installed) {
            $installed = $this->installRefundGuardTable() && $this->installWebhookWebservice();
        }

        return $installed;
    }

    public function uninstall(): bool
    {
        return $this->uninstallRefundGuardTable()
            && $this->uninstallWebhookWebservice()
            && parent::uninstall();
    }

    public function syncWebhookWebservice(): bool
    {
        return $this->installWebhookWebservice();
    }

    public function getContent(): string
    {
        $output = '';
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

            $installments_type = intval(Tools::getValue('installments_type'));
            Configuration::updateValue('installments_type', $installments_type);

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
                'title' => $this->l('Payment Gateway Configurations: ').GP_FLAVOR,
                'image' => $this->_path.'imgs/payment-logo.svg',
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
                    'label'    => $this->l('App Code Server:'),
                    'desc'     => $this->l('Unique commerce identifier to perform admin actions on ').GP_FLAVOR,
                    'name'     => 'app_code_server',
                    'required' => true
                ],
                [
                    'type'     => 'text',
                    'label'    => $this->l('App Key Server:'),
                    'desc'     => $this->l('Key used to encrypt admin communication with ').GP_FLAVOR,
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
                    'type'     => 'select',
                    'label'    => $this->l('Installments Type:'),
                    'desc'     => $this->l('Installments type sent to the payment gateway on card checkout. Select Disabled if not applicable.'),
                    'name'     => 'installments_type',
                    'required' => true,
                    'options'  => [
                        'query' => [
                            ['id_option' => -1, 'name' => $this->l('Disabled')],
                            ['id_option' => 0,  'name' => $this->l('Revolving credit (Colombia)')],
                            ['id_option' => 1,  'name' => $this->l('Revolving and deferred without interest (Ecuador)')],
                            ['id_option' => 2,  'name' => $this->l('Deferred with interest (Ecuador, México)')],
                            ['id_option' => 3,  'name' => $this->l('Deferred without interest (Ecuador, México)')],
                            ['id_option' => 6,  'name' => $this->l('Deferred without interest month by month (Ecuador - Medianet)')],
                            ['id_option' => 7,  'name' => $this->l('Deferred with interest and months of grace (Ecuador)')],
                            ['id_option' => 9,  'name' => $this->l('Deferred without interest and months of grace (Ecuador, México)')],
                            ['id_option' => 10, 'name' => $this->l('Deferred without interest bimonthly promotion (Ecuador - Medianet)')],
                            ['id_option' => 21, 'name' => $this->l('Diners Club deferred with/without interest (Ecuador)')],
                            ['id_option' => 22, 'name' => $this->l('Diners Club deferred with/without interest v2 (Ecuador)')],
                            ['id_option' => 30, 'name' => $this->l('Deferred with interest month by month (Ecuador - Medianet)')],
                            ['id_option' => 50, 'name' => $this->l('Deferred without interest Supermaxi promotions (Ecuador - Medianet)')],
                            ['id_option' => 51, 'name' => $this->l('Deferred with interest Cuota fácil (Ecuador - Medianet)')],
                            ['id_option' => 52, 'name' => $this->l('Without interest Redención Produmillas (Ecuador - Medianet)')],
                            ['id_option' => 53, 'name' => $this->l('Without interest sale promotions (Ecuador - Medianet)')],
                            ['id_option' => 70, 'name' => $this->l('Deferred special without interest (Ecuador - Medianet)')],
                            ['id_option' => 72, 'name' => $this->l('Credit without interest cte smax (Ecuador - Medianet)')],
                            ['id_option' => 73, 'name' => $this->l('Special credit without interest smax (Ecuador - Medianet)')],
                            ['id_option' => 74, 'name' => $this->l('Prepay without interest smax (Ecuador - Medianet)')],
                            ['id_option' => 75, 'name' => $this->l('Deferred credit without interest smax (Ecuador - Medianet)')],
                            ['id_option' => 90, 'name' => $this->l('Without interest with months of grace Supermaxi (Ecuador - Medianet)')],
                        ],
                        'id'   => 'id_option',
                        'name' => 'name',
                    ],
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();
        $adminModulesLink = $this->context->link->getAdminLink('AdminModules', false);

        // Module, token and currentIndex
        $helper->module          = $this;
        $helper->name_controller = $this->name;
        $helper->token           = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex    = $adminModulesLink.'&configure='.$this->name;

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
                'href' => $adminModulesLink.'&configure='.$this->name.'&save'.$this->name.
                    '&token='.Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => $adminModulesLink.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];

        // Load currents values
        $helper->fields_value['app_code_server']     = Tools::getValue('app_code_server', Configuration::get('app_code_server'));
        $helper->fields_value['app_key_server']      = Tools::getValue('app_key_server', Configuration::get('app_key_server'));
        $helper->fields_value['checkout_language']   = Tools::getValue('checkout_language', Configuration::get('checkout_language'));
        $helper->fields_value['environment']         = Tools::getValue('environment', Configuration::get('environment'));
        $helper->fields_value['enable_card']         = Tools::getValue('enable_card',  Configuration::get('enable_card'));
        $helper->fields_value['enable_ltp']          = Tools::getValue('enable_ltp',  Configuration::get('enable_ltp'));
        $helper->fields_value['ltp_button_text']     = Tools::getValue('ltp_button_text', Configuration::get('ltp_button_text'));
        $helper->fields_value['card_button_text']    = Tools::getValue('card_button_text', Configuration::get('card_button_text'));
        $helper->fields_value['ltp_expiration_days'] = intval(Tools::getValue('ltp_expiration_days', Configuration::get('ltp_expiration_days')));
        $helper->fields_value['installments_type']   = intval(Tools::getValue('installments_type', Configuration::get('installments_type')));

        return $helper->generateForm($fieldsForm);
    }

    public function hookPaymentOptions($params): array
    {
        if (!$this->active) {
            return [];
        }

        /**
         * Create a PaymentOption object containing the necessary data
         * to display this module in the checkout
         */


        $this->context->smarty->assign('module_dir', $this->_path);
        $this->context->smarty->assign(array(
            'flavor' => GP_FLAVOR,
        ));

        $actionParams = [];
        $cart = $this->context->cart;
        $customer = $this->context->customer;
        if (Validate::isLoadedObject($cart) && Validate::isLoadedObject($customer)) {
            $actionParams['pg_sig'] = Globalpay_PaymentUtils::buildFrontSecuritySignature($cart, $customer);
        }

        $newOption = new PaymentOption();
        $newOption->setModuleName($this->name)
            ->setCallToActionText($this->displayName)
            ->setLogo($this->_path . 'imgs/payment-logo.svg')
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', $actionParams, true))
            ->setAdditionalInformation($this->context->smarty->fetch('module:'.$this->name.'/views/templates/front/payment_infos.tpl'));

        return [$newOption];
    }

    public function hookActionProductCancel(array $params)
    {
        $this->processRefundRequest($params);
    }

    public function hookActionOrderSlipAdd(array $params)
    {
        $this->processRefundRequest($params);
    }

    private function processRefundRequest(array $params): void
    {
        if (self::$refundRequestHandled) {
            return;
        }

        if ((int) ($params['action'] ?? 1) !== 1 && empty($_POST['cancel_product'])) {
            return;
        }

        $order = $this->resolveRefundOrder($params);
        if (!$order instanceof Order || !Validate::isLoadedObject($order)) {
            return;
        }

        if (!Globalpay_PaymentUtils::isCardRefundableOrder($order)) {
            PrestaShopLogger::addLog('Globalpay refund skipped: only card payments are refundable.', 3);
            return;
        }

        $amount_to_refund = $this->resolveRefundAmount($params, $order);
        if ($amount_to_refund <= 0) {
            PrestaShopLogger::addLog('Globalpay refund skipped: refund amount is zero.', 3);
            return;
        }
        $amount_to_refund = $this->normalizeRefundAmount($amount_to_refund);

        $transaction_id = $this->getOrderTransactionId($order);
        if ($transaction_id === '') {
            PrestaShopLogger::addLog('Globalpay refund skipped: transaction id not found.', 3);
            return;
        }

        // Capture the current state before processing so we can revert if the gateway fails.
        // PrestaShop may change the order state itself (e.g. when creating an OrderSlip) before
        // our hook runs, so we must be ready to roll it back.
        $previous_order_state = (int) $order->current_state;

        $refund_guard_key = $this->buildRefundGuardKey($params, $order, $transaction_id, $amount_to_refund);
        if (!$this->reserveRefundRequest($refund_guard_key, $order, $transaction_id, $amount_to_refund)) {
            PrestaShopLogger::addLog('Globalpay refund skipped: duplicate refund request detected.', 3);
            return;
        }

        self::$refundRequestHandled = true;
        PrestaShopLogger::addLog(
            'Globalpay refund amount sent to gateway: ' . number_format($amount_to_refund, 2, '.', ''),
            1
        );

        $response = $this->sendRefundToGateway($transaction_id, $amount_to_refund);
        if ($response === null) {
            $this->releaseRefundRequest($refund_guard_key);
            $this->rollbackFailedRefund($params, $order, $previous_order_state);
            return;
        }

        $this->markRefundRequestProcessed($refund_guard_key, $response);

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($this->getRefundOrderStateId(), (int) $order->id);
        $history->save();
    }

    private function resolveRefundOrder(array $params)
    {
        if (isset($params['order']) && $params['order'] instanceof Order) {
            return $params['order'];
        }

        if (isset($params['order']) && is_object($params['order']) && !empty($params['order']->id)) {
            return new Order((int) $params['order']->id);
        }

        $idOrder = (int) (
            $params['id_order']
            ?? $_POST['id_order']
            ?? Tools::getValue('id_order')
            ?? 0
        );
        if ($idOrder > 0) {
            return new Order($idOrder);
        }

        return null;
    }

    private function resolveRefundAmount(array $params, Order $order): float
    {
        // CQRS params from actionProductCancel hook
        if (!empty($params['refunds']) && is_array($params['refunds'])) {
            PrestaShopLogger::addLog('Globalpay debug: CQRS refunds param found: ' . json_encode($params['refunds']), 1);
            $amount = $this->calculateRefundAmountFromCQRS($params['refunds'], $order, $params);
            if ($amount > 0) {
                PrestaShopLogger::addLog('Globalpay debug: calculateRefundAmountFromCQRS returned: ' . $amount, 1);
                return $amount;
            }
        }

        // Legacy: POST data from older PrestaShop versions or custom forms
        $cancel_product = (array) ($_POST['cancel_product'] ?? []);
        if (!empty($cancel_product)) {
            PrestaShopLogger::addLog('Globalpay debug: POST cancel_product data found: ' . json_encode($cancel_product), 1);
            $amount = $this->calculateRefundAmount($cancel_product, $order);
            PrestaShopLogger::addLog('Globalpay debug: calculateRefundAmount returned: ' . $amount, 1);
            if ($amount > 0) {
                return $amount;
            }
        }

        $orderSlipAmount = $this->resolveOrderSlipAmount($params);
        if ($orderSlipAmount > 0) {
            PrestaShopLogger::addLog('Globalpay debug: orderSlipAmount returned: ' . $orderSlipAmount, 1);
            return $orderSlipAmount;
        }

        foreach (['amount_to_refund', 'refund_amount', 'amount', 'total'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key])) {
                $amount = (float) $params[$key];
                if ($amount > 0) {
                    PrestaShopLogger::addLog('Globalpay debug: params[' . $key . '] returned: ' . $amount, 1);
                    return $amount;
                }
            }
        }

        return 0.0;
    }

    private function calculateRefundAmountFromCQRS(array $refunds, Order $order, array $params): float
    {
        $amount_to_refund = 0.0;

        // Add shipping refund amount if present
        $shipping_amount = (float) ($params['shippingCostRefundAmount'] ?? 0);
        if ($shipping_amount > 0) {
            $amount_to_refund += $shipping_amount;
            PrestaShopLogger::addLog('Globalpay debug: shipping_amount from CQRS = ' . $shipping_amount, 1);
        }

        // Sum all refund amounts from CQRS command
        foreach ($refunds as $orderDetailId => $refundData) {
            if (is_array($refundData) && isset($refundData['amount'])) {
                $refund_amount = (float) $refundData['amount'];
                $refund_qty = (int) ($refundData['quantity'] ?? 0);
                $amount_to_refund += $refund_amount;
                PrestaShopLogger::addLog('Globalpay debug: order_detail_id=' . $orderDetailId . ', qty=' . $refund_qty . ', amount=' . $refund_amount, 1);
            }
        }

        PrestaShopLogger::addLog('Globalpay debug: calculateRefundAmountFromCQRS final = ' . $amount_to_refund, 1);
        return $amount_to_refund;
    }

    private function resolveOrderSlipAmount(array $params): float
    {
        $amount = $this->extractRefundAmountFromSource($params['order_slip'] ?? null);
        if ($amount > 0) {
            PrestaShopLogger::addLog('Globalpay debug: amount from params[order_slip] = ' . $amount, 1);
            return $amount;
        }

        $amount = $this->extractRefundAmountFromSource($params['orderSlip'] ?? null);
        if ($amount > 0) {
            PrestaShopLogger::addLog('Globalpay debug: amount from params[orderSlip] = ' . $amount, 1);
            return $amount;
        }

        $idOrderSlip = (int) (
            $params['id_order_slip']
            ?? $params['order_slip']->id_order_slip
            ?? $params['order_slip']->id
            ?? $params['orderSlip']->id_order_slip
            ?? $params['orderSlip']->id
            ?? 0
        );

        PrestaShopLogger::addLog('Globalpay debug: trying to load OrderSlip with id = ' . $idOrderSlip, 1);

        if ($idOrderSlip <= 0) {
            return 0.0;
        }

        $orderSlip = new OrderSlip($idOrderSlip);
        if (!Validate::isLoadedObject($orderSlip)) {
            PrestaShopLogger::addLog('Globalpay debug: OrderSlip ' . $idOrderSlip . ' failed to load', 1);
            return 0.0;
        }

        PrestaShopLogger::addLog('Globalpay debug: OrderSlip loaded, extracting amount from object', 1);
        PrestaShopLogger::addLog('Globalpay debug: OrderSlip data = ' . json_encode(get_object_vars($orderSlip)), 1);
        
        $amount = $this->extractRefundAmountFromSource($orderSlip);
        if ($amount > 0) {
            PrestaShopLogger::addLog('Globalpay debug: amount from OrderSlip object = ' . $amount, 1);
        }
        
        return $amount;
    }

    private function sendRefundToGateway(string $transaction_id, float $amount_to_refund): ?array
    {
        $environment = Configuration::get('environment');
        $url = ($environment == 1)
            ? 'https://ccapi-stg.' . GP_FLAVOR_DOMAIN . GP_REFUND_PATH
            : 'https://ccapi.' . GP_FLAVOR_DOMAIN . GP_REFUND_PATH;

        $app_code_server = Configuration::get('app_code_server');
        $app_key_server = Configuration::get('app_key_server');
        $refund_data = [
            'transaction' => ['id' => $transaction_id],
            'order' => ['amount' => $this->normalizeRefundAmount($amount_to_refund)],
        ];
        $payload = json_encode($refund_data, JSON_PRESERVE_ZERO_FRACTION);
        if ($payload === false) {
            PrestaShopLogger::addLog('Globalpay refund payload encoding failed.', 3);
            return null;
        }

        $timestamp = (string) time();
        $uniq_token_hash = hash('sha256', $app_key_server . $timestamp);
        $auth_token = base64_encode($app_code_server . ';' . $timestamp . ';' . $uniq_token_hash);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type:application/json',
            'Auth-Token:' . $auth_token,
        ]);
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curl_error !== '') {
            PrestaShopLogger::addLog('Globalpay refund cURL error: ' . $curl_error, 3);
            return null;
        }

        $get_response = json_decode($response, true);
        if (!is_array($get_response) || !empty($get_response['error']) || (($get_response['status'] ?? '') == 'failure')) {
            PrestaShopLogger::addLog('Globalpay refund failed. Response: ' . (string) $response, 3);
            return null;
        }

        return $get_response;
    }

    private function calculateRefundAmount(array $cancel_product, Order $order): float
    {
        $amount_to_refund = 0.0;

        $shipping_amount = $this->extractShippingRefundAmount($cancel_product, $order);
        if ($shipping_amount > 0) {
            $amount_to_refund += $shipping_amount;
            PrestaShopLogger::addLog('Globalpay debug: shipping_amount = ' . $shipping_amount, 1);
        }

        // PrestaShop sends amounts directly as amount_X where X is order_detail_id
        $keys_to_clean = ['_token', 'save', 'voucher_refund_type', 'voucher', 'credit_slip', 'shipping_amount', 'shipping', 'restock'];
        foreach ($keys_to_clean as $key) {
            unset($cancel_product[$key]);
        }

        // Sum all amount_X values (PrestaShop pre-calculates these)
        foreach ($cancel_product as $key => $value) {
            if (strpos($key, 'amount_') === 0) {
                $refund_amount = (float) $value;
                if ($refund_amount > 0) {
                    $order_detail_id = str_replace('amount_', '', $key);
                    PrestaShopLogger::addLog('Globalpay debug: order_detail_id=' . $order_detail_id . ', amount=' . $refund_amount, 1);
                    $amount_to_refund += $refund_amount;
                }
            }
        }

        // Fallback to old format (selected_X + quantity_X) for backward compatibility
        if ($amount_to_refund == 0) {
            $selected = [];
            $quantity = [];
            foreach (array_keys($cancel_product) as $key) {
                if (strpos($key, 'selected') !== false) {
                    $id_order_detail = (string) explode('_', $key)[1];
                    $selected[$id_order_detail] = $cancel_product[$key];
                } elseif (strpos($key, 'quantity') !== false) {
                    $id_order_detail = (string) explode('_', $key)[1];
                    $quantity[$id_order_detail] = $cancel_product[$key];
                }
            }

            PrestaShopLogger::addLog('Globalpay debug: selected = ' . json_encode($selected), 1);
            PrestaShopLogger::addLog('Globalpay debug: quantity = ' . json_encode($quantity), 1);

            foreach ($selected as $key => $value) {
                if ($value) {
                    $order_detail = new OrderDetail((int) $key);
                    $qty = (float) ($quantity[$key] ?? 0);
                    $unit_price = (float) $order_detail->unit_price_tax_incl;
                    $product_amount = $qty * $unit_price;
                    PrestaShopLogger::addLog('Globalpay debug: order_detail_id=' . $key . ', qty=' . $qty . ', unit_price=' . $unit_price . ', product_amount=' . $product_amount, 1);
                    $amount_to_refund += $product_amount;
                }
            }
        }

        PrestaShopLogger::addLog('Globalpay debug: calculateRefundAmount final = ' . $amount_to_refund, 1);
        return $amount_to_refund;
    }

    private function normalizeRefundAmount(float $amount): float
    {
        return round(max(0.0, $amount), 2, PHP_ROUND_HALF_DOWN);
    }

    private function buildRefundGuardKey(array $params, Order $order, string $transaction_id, float $amount_to_refund): string
    {
        $orderSlipId = $this->resolveOrderSlipId($params);
        if ($orderSlipId > 0) {
            return 'order-slip:' . $orderSlipId;
        }

        $cancelProduct = (array) ($_POST['cancel_product'] ?? []);
        $sourceSignature = $this->buildRefundSourceSignature($cancelProduct);

        return hash(
            'sha256',
            implode('|', [
                (string) $order->id,
                (string) $order->reference,
                $transaction_id,
                number_format($amount_to_refund, 2, '.', ''),
                $sourceSignature,
            ])
        );
    }

    private function buildRefundSourceSignature(array $cancel_product): string
    {
        if (empty($cancel_product)) {
            return '';
        }

        $normalized = [];
        foreach ($cancel_product as $key => $value) {
            if ($key === '_token' || $key === 'save') {
                continue;
            }

            if (strpos((string) $key, 'selected') === false && strpos((string) $key, 'quantity') === false && !in_array($key, ['shipping_amount', 'shipping', 'credit_slip', 'voucher_refund_type', 'voucher'], true)) {
                continue;
            }

            $normalized[$key] = is_scalar($value) ? (string) $value : (json_encode($value) ?: '');
        }

        ksort($normalized);
        return hash('sha256', json_encode($normalized) ?: '');
    }

    private function rollbackFailedRefund(array $params, Order $order, int $previous_order_state): void
    {
        // 1. Delete the OrderSlip that PrestaShop already created for this failed refund
        $orderSlipId = $this->resolveOrderSlipId($params);
        if ($orderSlipId > 0) {
            $orderSlip = new OrderSlip($orderSlipId);
            if (Validate::isLoadedObject($orderSlip) && (int) $orderSlip->id_order === (int) $order->id) {
                Db::getInstance()->delete(
                    'order_slip_detail',
                    '`id_order_slip` = ' . (int) $orderSlipId
                );
                Db::getInstance()->delete(
                    'order_slip_detail_tax',
                    '`id_order_slip_detail` IN (SELECT id_order_slip_detail FROM `' . _DB_PREFIX_ . 'order_slip_detail` WHERE id_order_slip = ' . (int) $orderSlipId . ')'
                );
                $orderSlip->delete();
                PrestaShopLogger::addLog(
                    'Globalpay refund failed: deleted OrderSlip #' . $orderSlipId . ' for order #' . $order->id,
                    3
                );
            }
        }

        // 2. Revert the order state if PrestaShop already changed it
        $order = new Order((int) $order->id);
        if ((int) $order->current_state !== $previous_order_state) {
            $revert = new OrderHistory();
            $revert->id_order = (int) $order->id;
            $revert->changeIdOrderState($previous_order_state, (int) $order->id);
            $revert->save();
            PrestaShopLogger::addLog(
                'Globalpay refund failed: order #' . $order->id . ' state reverted to ' . $previous_order_state,
                3
            );
        }

        // 3. Notify the admin user in the back office (both inline and via cookie for post-redirect display)
        $this->notifyRefundFailure();
    }

    private function notifyRefundFailure(): void
    {
        $message = $this->l('Globalpay refund failed: the gateway rejected the refund request. The order was not refunded. Please check the logs and try again.');

        // Inline error for current controller (works if PrestaShop renders the response now)
        if (isset($this->context->controller) && is_object($this->context->controller)) {
            if (property_exists($this->context->controller, 'errors') && is_array($this->context->controller->errors)) {
                $this->context->controller->errors[] = $message;
            }
        }

        // Cookie-based flash message (survives the redirect PrestaShop usually performs after a refund)
        if (isset($this->context->cookie) && is_object($this->context->cookie)) {
            $this->context->cookie->__set('globalpay_refund_error', $message);
            $this->context->cookie->write();
        }
    }

    private function resolveOrderSlipId(array $params): int
    {
        $idOrderSlip = (int) ($params['id_order_slip'] ?? 0);
        if ($idOrderSlip > 0) {
            return $idOrderSlip;
        }

        foreach (['order_slip', 'orderSlip'] as $key) {
            if (!isset($params[$key]) || !is_object($params[$key])) {
                continue;
            }

            $idOrderSlip = (int) ($params[$key]->id_order_slip ?? $params[$key]->id ?? 0);
            if ($idOrderSlip > 0) {
                return $idOrderSlip;
            }
        }

        return 0;
    }

    private function reserveRefundRequest(string $refund_guard_key, Order $order, string $transaction_id, float $amount_to_refund): bool
    {
        $sql = 'INSERT IGNORE INTO `' . _DB_PREFIX_ . GP_REFUND_GUARD_TABLE . '`
            (`refund_key`, `id_order`, `transaction_id`, `amount`, `status`, `created_at`, `updated_at`)
            VALUES (
                "' . pSQL($refund_guard_key) . '",
                ' . (int) $order->id . ',
                "' . pSQL($transaction_id) . '",
                ' . (float) $amount_to_refund . ',
                "pending",
                NOW(),
                NOW()
            )';

        if (!Db::getInstance()->execute($sql)) {
            return false;
        }

        return (int) Db::getInstance()->getValue('SELECT ROW_COUNT()') === 1;
    }

    private function markRefundRequestProcessed(string $refund_guard_key, array $response): bool
    {
        $sql = 'UPDATE `' . _DB_PREFIX_ . GP_REFUND_GUARD_TABLE . '`
            SET `status` = "processed",
                `response_json` = "' . pSQL(json_encode($response, JSON_PRESERVE_ZERO_FRACTION) ?: '') . '",
                `updated_at` = NOW()
            WHERE `refund_key` = "' . pSQL($refund_guard_key) . '"';

        return (bool) Db::getInstance()->execute($sql);
    }

    private function releaseRefundRequest(string $refund_guard_key): bool
    {
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . GP_REFUND_GUARD_TABLE . '`
            WHERE `refund_key` = "' . pSQL($refund_guard_key) . '" AND `status` = "pending"';

        return (bool) Db::getInstance()->execute($sql);
    }

    private function installRefundGuardTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . GP_REFUND_GUARD_TABLE . '` (
            `id_refund_guard` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `refund_key` VARCHAR(128) NOT NULL,
            `id_order` INT UNSIGNED NOT NULL,
            `transaction_id` VARCHAR(128) NOT NULL,
            `amount` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
            `status` VARCHAR(20) NOT NULL DEFAULT "pending",
            `response_json` MEDIUMTEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_refund_guard`),
            UNIQUE KEY `refund_key_unique` (`refund_key`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        if (!Db::getInstance()->execute($sql)) {
            PrestaShopLogger::addLog('Globalpay could not create the refund guard table.', 3);
            return false;
        }

        return true;
    }

    private function uninstallRefundGuardTable(): bool
    {
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . GP_REFUND_GUARD_TABLE . '`');
    }

    private function extractShippingRefundAmount(array $cancel_product, Order $order): float
    {
        if (isset($cancel_product['shipping_amount']) && is_numeric($cancel_product['shipping_amount'])) {
            $shipping_amount = (float) $cancel_product['shipping_amount'];
            if ($shipping_amount > 0) {
                return $shipping_amount;
            }
        }

        if (!empty($cancel_product['shipping'])) {
            return (float) $order->total_shipping;
        }

        return 0.0;
    }

    private function extractRefundAmountFromSource($source): float
    {
        if (is_object($source)) {
            $source = get_object_vars($source);
        }

        if (!is_array($source)) {
            return 0.0;
        }

        foreach ([
            'amount_to_refund',
            'refund_amount',
            'amount',
            'total',
            'amount_tax_incl',
            'total_amount',
            'total_paid_tax_incl',
            'total_products_tax_incl',
            'total_shipping_tax_incl',
            'total_products_wt',
        ] as $key) {
            if (isset($source[$key]) && is_numeric($source[$key])) {
                $amount = (float) $source[$key];
                if ($amount > 0) {
                    return $amount;
                }
            }
        }

        // For OrderSlip objects, sum products + shipping if individual fields exist
        $products_amount = (float) ($source['total_products_tax_incl'] ?? 0);
        $shipping_amount = (float) ($source['total_shipping_tax_incl'] ?? 0);
        if ($products_amount > 0 || $shipping_amount > 0) {
            return $products_amount + $shipping_amount;
        }

        return 0.0;
    }

    private function getOrderTransactionId(Order $order): string
    {
        $collection = OrderPayment::getByOrderReference($order->reference);
        if (count($collection) === 0) {
            return '';
        }

        foreach ($collection as $order_payment) {
            if ($order_payment->payment_method == GP_FLAVOR . ' Prestashop Plugin') {
                return (string) $order_payment->transaction_id;
            }
        }

        return '';
    }

    private function getRefundOrderStateId(): int
    {
        $refundStateId = (int) Configuration::get('PS_OS_REFUND');
        return $refundStateId > 0 ? $refundStateId : 7;
    }

    public function hookHeader()
    {
        $this->context->controller->registerStylesheet(
            'front-css',
            'modules/' . $this->name . '/views/css/main.css'
        );
    }

    public function hookDisplayBackOfficeHeader(): string
    {
        $output = $this->renderRefundFailureFlash();

        if ((string) Tools::getValue('configure') === $this->name) {
            return $output . '<style>
                img[src*="/modules/' . $this->name . '/imgs/payment-logo.svg"] {
                    width: 50% !important;
                    height: auto !important;
                }
            </style>';
        }

        $idOrder = (int) Tools::getValue('id_order');
        if ($idOrder <= 0) {
            return $output;
        }

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order) || !Globalpay_PaymentUtils::isLinkToPayOrder($order)) {
            return $output;
        }

        return $output . '<script>
            document.addEventListener("DOMContentLoaded", function () {
                var nodes = document.querySelectorAll("button, input[type=\\"submit\\"], a.btn");
                nodes.forEach(function (node) {
                    var text = (node.innerText || node.value || "").toLowerCase();
                    if (text.indexOf("refund") !== -1 || text.indexOf("reembolso") !== -1) {
                        node.style.display = "none";
                    }
                });
            });
        </script>';
    }

    private function renderRefundFailureFlash(): string
    {
        if (!isset($this->context->cookie) || !is_object($this->context->cookie)) {
            return '';
        }

        $message = (string) ($this->context->cookie->__get('globalpay_refund_error') ?? '');
        if ($message === '') {
            return '';
        }

        // Consume the flash so it only shows once
        $this->context->cookie->__unset('globalpay_refund_error');
        $this->context->cookie->write();

        return '<script>
            document.addEventListener("DOMContentLoaded", function () {
                var alertBox = document.createElement("div");
                alertBox.className = "alert alert-danger";
                alertBox.setAttribute("role", "alert");
                alertBox.style.margin = "15px";
                alertBox.textContent = ' . json_encode($message) . ';
                var container = document.querySelector(".content-div, #content, .page-content, body");
                if (container) {
                    container.insertBefore(alertBox, container.firstChild);
                }
            });
        </script>';
    }

    public function hookDisplayPaymentReturn($params): string
    {
        if (!$this->active) {
            return '';
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

        $ltp_status = (string) Tools::getValue('ltp_status');
        $pg_approved = $ltp_status === 'success' ? true : ($this->context->cookie->pg_payment_approved === '1');
        $ltp_failed = $ltp_status === 'failed' || ($this->context->cookie->ltp_failed ?? '') === '1';
        $ltp_pending = $ltp_status === 'pending' || ($this->context->cookie->ltp_pending ?? '') === '1';
        $ltp_review = $ltp_status === 'review' || ($this->context->cookie->ltp_review ?? '') === '1';
        unset($this->context->cookie->pg_payment_approved);
        unset($this->context->cookie->ltp_failed);
        unset($this->context->cookie->ltp_pending);
        unset($this->context->cookie->ltp_review);

        $this->context->smarty->assign([
            'payment_id' => $transaction_id,
            'module_gtw' => $this->displayName,
            'pg_payment_approved' => $pg_approved,
            'ltp_failed' => $ltp_failed,
            'ltp_pending' => $ltp_pending,
            'ltp_review' => $ltp_review,
        ]);
        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }

    public function hookPaymentReturn($params): string
    {
        return $this->hookDisplayPaymentReturn($params);
    }

    public function hookAddWebserviceResources(): array
    {
        return array(
            GP_WEBHOOK_RESOURCE_NAME => array(
                'description'         => GP_FLAVOR.' Webhook Management.',
                'specific_management' => true
            )
        );
    }

    private function getWebserviceAccountIdByKey(string $key): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_webservice_account` FROM `'._DB_PREFIX_.'webservice_account` WHERE `api_key` = "'.pSQL($key).'"'
        );
    }

    private function installWebhookWebservice(): bool
    {
        try {
            $permissions = $this->getWebhookPermissions();
            $storedKey = (string) Configuration::get(GP_WEBHOOK_WS_CONFIG_KEY);
            if ($storedKey !== '') {
                $accountId = $this->getWebserviceAccountIdByKey($storedKey);
                if ($accountId > 0) {
                    return WebserviceKey::setPermissionForAccount($accountId, $permissions);
                }
            }

            $generatedKey = bin2hex(random_bytes(16));

            $account = new WebserviceKey();
            $account->key = $generatedKey;
            $account->description = GP_FLAVOR . ' webhook';
            $account->active = true;

            if (!$account->add()) {
                PrestaShopLogger::addLog('Globalpay could not create the webhook webservice key.', 3);
                return false;
            }

            if (!WebserviceKey::setPermissionForAccount((int) $account->id, $permissions)) {
                $account->delete();
                PrestaShopLogger::addLog('Globalpay could not configure webhook permissions.', 3);
                return false;
            }

            return Configuration::updateValue(GP_WEBHOOK_WS_CONFIG_KEY, $generatedKey);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('Globalpay webhook setup failed: ' . $e->getMessage(), 3);
            return false;
        }
    }

    private function getWebhookPermissions(): array
    {
        return [
            GP_WEBHOOK_RESOURCE_NAME => [
                'POST' => true,
            ],
        ];
    }

    private function uninstallWebhookWebservice(): bool
    {
        $storedKey = (string) Configuration::get(GP_WEBHOOK_WS_CONFIG_KEY);
        if ($storedKey !== '') {
            $accountId = $this->getWebserviceAccountIdByKey($storedKey);
            if ($accountId > 0) {
                $account = new WebserviceKey($accountId);
                if ($account->id) {
                    $account->delete();
                }
            }
            return Configuration::deleteByName(GP_WEBHOOK_WS_CONFIG_KEY);
        }

        return true;
    }
}

// Allow PrestaShop to find this module class regardless of the folder name.
// When installed as 'pg_prestashop_plugin', an alias is created so PS can
// instantiate the class by the folder name. When installed as 'globalpay_payment',
// Globalpay_Payment already resolves correctly (PHP class names are case-insensitive).
$_gp_module_alias = basename(__DIR__);
if (!class_exists($_gp_module_alias, false)) {
    class_alias('Globalpay_Payment', $_gp_module_alias);
}
unset($_gp_module_alias);