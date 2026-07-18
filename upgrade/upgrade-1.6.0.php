<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.6.0 — registra hooks de add-to-cart (Cart::updateQty) sem reinstalar.
 *
 * @param smartvitrines $module
 *
 * @return bool
 */
function upgrade_module_1_6_0($module)
{
    if (!$module instanceof smartvitrines) {
        return false;
    }

    return (bool) $module->registerAddToCartHooks();
}
