<?php

function upgrade_module_3_0_1($module)
{
    if (!($module instanceof Globalpay_Payment)) {
        return false;
    }

    return $module->registerHook('addWebserviceResources')
        && $module->registerHook('actionOrderSlipAdd')
        && $module->registerHook('displayBackOfficeHeader')
        && $module->syncWebhookWebservice();
}
