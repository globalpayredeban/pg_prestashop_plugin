# Globalpay Payment Gateway Plugin for Prestashop

## 1.- Prerequisites
### 1.1.- XAMPP, LAMPP, MAMPP, Bitnami or any PHP development environment
- XAMPP: https://www.apachefriends.org/download.html
- LAMPP: https://www.apachefriends.org/download.html
- MAMPP: https://www.mamp.info/en/mac/
- Bitnami: https://bitnami.com/stack/prestashop
### 1.2.- Prestashop
If you already install the Bitnami option this steps can be omitted.

Prestashop is an e-commerce solution, it's developed on PHP. Now the last stable version is the 1.7.X.
- Download: https://www.prestashop.com/en/download
- Install Guide: https://www.prestashop.com/en/blog/how-to-install-prestashop

## 2.- Git Repository
You can download the current stable release from: https://github.com/globalpayredeban/pg_prestashop_plugin/releases

## 3.- Plugin Installation on Prestashop
1. First, we need to downoload the current stable release of Globalpay Prestashop plugin from the previus step.
2. We need to login to our Prestashop admin page.
3. Now we click on **Improve -> Modules -> Module Manager**
4. In the Module manager we click on the **Upload a mudule** button
5. We click on **select file** or we can **Drop** the Globalpay Prestashop plugin folder on .zip or .rar format.
6. We will wait until the **Installing module** screen changes to **Module installed!**.
7. Now we can click on **Configure** button displayed on the screen or in the **Configure** button displayed on the **Payment** section on the **Module manager**.
8. Inside of the **Payment Gateway Configurations** we need to configure or CLIENT/SERVER credentials provided by **Globalpay**, we can select the **Checkout Language** that will be displayed to the user, also we need to select an **Environment**, by default STG(Staging) is selected.
9. Congrats! Now we have the Globalpay Prestashop plugin correctly configured.

## 4.- Considerations and Comments
### 4.1.- Refunds
- The **1.0.0** plugin version does not support the **Partial Refunds** by Prestashop. However the plugin supports **Standard Refunds** by Prestashop. 
- The **Standard Refund** can be interpreted as a partial refund on Globalpay side, the success of the operation depends on the configured payment network accepting partial refunds.
### 4.2.- Webhook
- The Globalpay Prestashop plugin has an internal webhook in order to keep updated the transactions statuses between Prestashop and Globalpay.
- The webhook its located on **http://{my_store}/prestashop/es/module/pg_prestashop_plugin/webhook**, this url will be configured on the CLIENT/SERVER credentials.
