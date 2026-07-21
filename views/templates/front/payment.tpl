{extends "$layout"}
{block name="content"}
    <script src="https://cdn.globalpay.com.co/ccapi/sdk/payment_checkout_3.0.0.min.js"></script>

    <div class="row">
        <div class="payment-title col-sm-7 col-lg-9">
            <h3 class="text-xs-center text-md-left">
                <span>
                    {l s='Review your items to checkout' mod='globalpay_payment'}
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
                        <span class="text-primary">{$product.price_wt|escape:'html':'UTF-8'}</span> - <span
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
                        {l s='Modify or delete items' mod='globalpay_payment'}
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
            {if $enable_card}
                <button class="btn btn-primary btn-block js-payment-checkout">
                    <i class="material-icons">done</i>
                    <span>
                    {$card_button_text}
                </span>
                </button>
            {/if}
            {if $enable_ltp}
                <button class="btn btn-primary btn-block ltp-button">
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
       jQuery(document).ready(function ($) {
           function setProcessing(state) {
               window.globalpayPaymentProcessing = state;
               $('.js-payment-checkout, .ltp-button').prop('disabled', state);
           }

           $('.ltp-button').on('click', function (e) {
               e.preventDefault();
               if (window.globalpayPaymentProcessing) {
                   return;
               }
               setProcessing(true);
               let xhr = new XMLHttpRequest();
               xhr.open("POST", "{$ltp_init_url nofilter}", true);
               xhr.setRequestHeader('Content-Type', 'application/json');
               xhr.send();
               xhr.onload = function() {
                   let data = JSON.parse(this.responseText);
                   if (data.success) {
                       window.location.href = data.payment_url;
                   } else {
                       let errorMessage = "{l s='Failed to generate the LinkToPay, gateway response: ' mod='globalpay_payment'}";
                       setProcessing(false);
                       window.alert(errorMessage + (data.error || ''));
                   }
               };
               xhr.onerror = function() {
                   setProcessing(false);
                   window.alert('{l s='Error communicating with LinkToPay gateway.' mod='globalpay_payment'}');
               };
           });
       });
    </script>

    <script id="payment_checkout" type="text/javascript">
        jQuery(document).ready(function ($) {
            function setProcessing(state) {
                window.globalpayPaymentProcessing = state;
                $('.js-payment-checkout, .ltp-button').prop('disabled', state);
            }

            let paymentCheckout = new PaymentCheckout.modal({
                locale: "{$checkout_language}",
                env_mode: "{$environment}",
                onOpen: function () {},
                onClose: function () {
                    setProcessing(false);
                },
                onResponse: function (response) {
                    if (response.transaction["status_detail"] === 3) {
                        redirectCardPost(response);
                    } else {
                        setProcessing(false);
                        window.alert('{l s='An error occurred while processing your payment and could not be made. Try another Credit Card.' mod='globalpay_payment'}');
                    }
                }
            });

            $('.js-payment-checkout').on('click', function () {
                if (window.globalpayPaymentProcessing) {
                    return;
                }
                setProcessing(true);
                let xhr = new XMLHttpRequest();
                xhr.open("POST", "{$card_init_url nofilter}", true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.send();
                xhr.onload = function() {
                    let data = JSON.parse(this.responseText);
                    if (data.success) {
                        paymentCheckout.open({
                            reference: data.reference
                        });
                    } else {
                        setProcessing(false);
                        window.alert('{l s='Failed to get checkout reference, please try again.' mod='globalpay_payment'}');
                    }
                };
                xhr.onerror = function() {
                    setProcessing(false);
                    window.alert('{l s='Error communicating with payment gateway.' mod='globalpay_payment'}');
                };
            });

            window.addEventListener('popstate', function () {
                paymentCheckout.close();
            });

            function redirectCardPost(params) {
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
                const signatureField = document.createElement('input');
                signatureField.type = 'hidden';
                signatureField.name = 'pg_sig';
                signatureField.value = "{$pg_sig|escape:'html':'UTF-8'}";
                form.appendChild(signatureField);
                document.body.appendChild(form);
                form.submit();
            }
        });
    </script>
{/block}
