<?php
/**
 * Bootstrap entry point for PrestaShop when this module is installed
 * with the folder renamed to "globalpay_payment" (to coexist with the other
 * Payment plugin). PrestaShop requires the main file name to match
 * the module folder name, so this file delegates to the real module file.
 */
require_once __DIR__ . '/pg_prestashop_plugin.php';
