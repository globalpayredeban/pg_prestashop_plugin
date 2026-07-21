{if $ltp_failed}
<h3>{l s='Payment failed' mod='globalpay_payment'}</h3>
<p>
    {l s='Your LinkToPay payment was not completed.' mod='globalpay_payment'}<br/>
    {l s='Please try again or choose another payment method.' mod='globalpay_payment'}
</p>
{elseif $ltp_pending}
<h3>{l s='Payment pending' mod='globalpay_payment'}</h3>
<p>
    {l s='Your LinkToPay payment is pending confirmation.' mod='globalpay_payment'}<br/>
    {l s='You will receive an email once it is approved.' mod='globalpay_payment'}
</p>
{elseif $ltp_review}
<h3>{l s='Payment under review' mod='globalpay_payment'}</h3>
<p>
    {l s='Your LinkToPay payment is under review.' mod='globalpay_payment'}<br/>
    {l s='We will notify you once it is resolved.' mod='globalpay_payment'}
</p>
{elseif $pg_payment_approved}
<h3>{l s='Payment approved!' mod='globalpay_payment'}</h3>
<p>
    {l s='Your payment was approved by %s.' sprintf=[$module_gtw] mod='globalpay_payment'}<br/>
    {l s='Your order is being confirmed and you will receive an email once it is processed.' mod='globalpay_payment'}
</p>
{else}
<h3>{l s='Order received' mod='globalpay_payment'}</h3>
<p>
    {l s='Your order has been received and payment is being verified.' mod='globalpay_payment'}<br/>
    {l s='You will receive a confirmation email once your payment is processed.' mod='globalpay_payment'}
</p>
{/if}
{if $payment_id}
<p>{l s='Payment reference: %s' sprintf=[$payment_id] mod='globalpay_payment'}</p>
{/if}