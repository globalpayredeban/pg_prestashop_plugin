{extends "$layout"}
{block name="content"}
    <link rel="stylesheet" href="{$urls.base_url}/modules/pg_prestashop_plugin/views/css/main.css">
    <script src="https://cdn.globalpay.com.co/ccapi/sdk/payment_checkout_stable.min.js"></script>
    <script src="https://code.jquery.com/jquery-1.7.1.min.js" type="text/javascript"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/3.1.9-1/crypto-js.js"></script></head>

    <div class="row">
        <div class="payment-title col-sm-7 col-lg-9">
            <h3 class="text-xs-center text-md-left">
                <span>
                    {l s='Review your items to checkout' mod='pg_prestashop_plugin'}
                </span>
            </h3>
        </div>
    </div>
    <hr>
    <div class="row">
        <div class="col-md-7">
            <ul class="list-group list-group-flush">
                {foreach $products as $product}
                    <li class="list-group-item">
                    <span class="item-name text-dark">
                        <b>{$product.name}</b>
                    </span><br>
                        {if $product.attributes}
                            <span class="item-name text-dark">
                        {$product.attributes}
                    </span><br>
                        {/if}
                        <span class="item-price">
                        <span class="text-primary">{Tools::displayPrice($product.price_wt)}</span> - <span
                                    class="text-muted">Cantidad:
                            {$product.quantity}</span>
                    </span><br>
                        {if $product.attributes}
                            <span class="items-details">
                        {$product.description_short nofilter}
                    </span>
                        {/if}
                    </li>
                {/foreach}
            </ul>
            <p class="payment-modify text-xs-center text-md-left">
                <a href="{$urls.pages.cart}?action=show">
                    <span>
                        {l s='Modify or delete items' mod='pg_prestashop_plugin'}
                    </span>
                </a>
            </p>
        </div>
    </div>
    <hr>
    <div class="row mb-1">
        <div class="col-sm-7 col-lg-9">
        </div>
        <div class="col-sm-5 col-lg-3">
            {if $enable_installments && $currency!=="COP"}
                <select class="btn btn-outline-primary dropdown-toggle btn-block" name="installments_type" id="installments_type">
                    <option selected disabled>{l s='Installments Type:' mod='pg_prestashop_plugin'}</option>
                    <option value=-1>{l s='Without Installments' mod='pg_prestashop_plugin'}</option>
                    {foreach $installments_options as $value => $text}
                        <option value={$value}>{$text}</option>
                    {/foreach}
                </select>
            {/if}
            {if $enable_card}
                <button class="btn btn-primary btn-block js-payment-checkout">
                    <i class="material-icons">done</i>
                    <span>
                    {$card_button_text}
                </span>
                </button>
            {/if}
            {if $enable_ltp}
                <button class="btn btn-primary btn-block ltp-button" onclick="ltpRedirect()">
                    <i class="material-icons">done</i>
                    <span>
                    {$ltp_button_text}
                </span>
                </button>
            {/if}
        </div>
    </div>

    <div id="response"></div>

    <script id="payment_ltp" type="text/javascript">
        function generateAuthToken() {
            let timestamp = (new Date()).getTime();
            let key_time = "{$app_key_server}" + timestamp;
            let uniq_token = CryptoJS.SHA256(key_time);
            let str_union = "{$app_code_server}"+";"+timestamp+";"+uniq_token
            return btoa(str_union);
        }

        function ltpRedirect() {
            let xhr = new XMLHttpRequest();
            xhr.open("POST", "{$ltp_url}", true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('Auth-token', generateAuthToken());
            xhr.send(JSON.stringify({
                "user": {
                    "id": "{$user_id}",
                    "email": "{$user_email}",
                    "name": "{$user_firstname}",
                    "last_name": "{$user_lastname}"
                },
                "order": {
                    "dev_reference": "{$order_reference}",
                    "description": "{$order_description}",
                    "amount": Number("{$order_amount}"),
                    "installments_type": -1,
                    "vat": Number("{$order_vat}"),
                    "currency": "{$currency}"
                },
                "configuration": {
                    "partial_payment": false,
                    "expiration_days": Number("{$ltp_expiration_days}"),
                    "allowed_payment_methods": ["All"],
                    "success_url": "{$order_url}",
                    "failure_url": "{$order_url}",
                    "pending_url": "{$order_url}",
                    "review_url": "{$order_url}"
                }
            }));
            xhr.onload = function() {
                let data = JSON.parse(this.responseText);
                if (!data.hasOwnProperty('success')) {
                    showErrorMessage(this.responseText)
                } else if (!data['success']) {
                    showErrorMessage(data['detail'] ?? this.responseText)
                } else {
                    redirectPost(data['data']);
                }
            }
        }

        function showErrorMessage(message) {
            let errorMessage = "{l s='Failed to generate the LinkToPay, gateway response: ' mod='pg_prestashop_plugin'}";
            window.alert(errorMessage + JSON.stringify(message));
        }

        function redirectPost(params) {
            params['payment']['payment_method'] = 'LinkToPay';
            params['payment']['id'] = params['order']['id'];
            params['payment']['amount'] = params['order']['amount'];

            const form = document.createElement('form');
            form.method = 'post';
            for (const key in params) {
                if (params.hasOwnProperty(key) && key === 'payment') {
                    for (const t_key in params[key]) {
                        const hiddenField = document.createElement('input');
                        hiddenField.type = 'hidden';
                        hiddenField.name = t_key;
                        hiddenField.value = params[key][t_key];
                        form.appendChild(hiddenField);
                    }
                }
            }

            document.body.appendChild(form);
            form.submit();
        }
    </script>

    <script id="payment_checkout" type="text/javascript">
        jQuery(document).ready(function ($) {
            let paymentCheckout = new PaymentCheckout.modal({
                client_app_code: "{$app_code_client}",
                client_app_key: "{$app_key_client}",
                locale: "{$checkout_language}",
                env_mode: "{$environment}",
                onOpen: function () {
                    console.log('modal open');
                },
                onClose: function () {
                    console.log('modal closed');
                },
                onResponse: function (response) {
                    console.log('modal response');
                    if (response.transaction["status_detail"] === 3 || response.transaction["status_detail"] === 0) {
                        showMessageSuccess(response);
                    } else {
                        showMessageError();
                    }
                }
            });

            let btnOpenCheckout = $('.js-payment-checkout');
            let type_installments = -1;
            if ("{$currency}" === "COP" && {$enable_installments})
                type_installments = 0;

            let order_installments_type = document.getElementById('installments_type') ? document.getElementById('installments_type').value : type_installments;

            btnOpenCheckout.each(function () {
                    $(this).on('click', function () {
                        paymentCheckout.open({
                            user_id: "{$user_id}",
                            user_email: "{$user_email}", //optional
                            order_description: "{$order_description}",
                            order_amount: Number("{$order_amount}"),
                            order_vat: Number("{$order_vat}"),
                            order_reference: "{$order_reference}",
                            order_installments_type: Number(order_installments_type),
                            billing_address: {
                                street: "{$billing_address.street}",
                                city: "{$billing_address.city}",
                                country: "{$billing_address.country}",
                                state:  "{$billing_address.state}",
                                zip: "{$billing_address.zip}"
                            }
                        });
                    })
                }
            );

            // Close Checkout on page navigation:
            window.addEventListener('popstate', function () {
                paymentCheckout.close();
            });

            function showMessageSuccess(params) {
                console.log("success");
                redirectPost(params);
            }

            function redirectPost(params) {
                params['transaction']['payment_method'] = 'Card';
                const form = document.createElement('form');
                form.method = 'post';

                for (const key in params) {
                    if (params.hasOwnProperty(key) && key === 'transaction') {
                        for (const t_key in params[key]) {
                            const hiddenField = document.createElement('input');
                            hiddenField.type = 'hidden';
                            hiddenField.name = t_key;
                            hiddenField.value = params[key][t_key];
                            form.appendChild(hiddenField);
                        }
                    }
                }

                document.body.appendChild(form);
                form.submit();
            }

            function showMessageError() {
                console.error("error");
                window.alert('{l s='An error occurred while processing your payment and could not be made. Try another Credit Card.' mod='pg_prestashop_plugin'}');
            }
        });
    </script>
{/block}
