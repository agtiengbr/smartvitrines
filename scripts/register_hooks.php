<?php

declare(strict_types=1);

/**
 * One-off: docker exec ps_modulos_web php modules/smartvitrines/scripts/register_hooks.php
 */
$configCandidates = [
    dirname(__DIR__, 3) . '/config/config.inc.php',
    dirname(__DIR__, 3) . '/public_html/config/config.inc.php',
];

$configLoaded = false;
foreach ($configCandidates as $configPath) {
    if (is_file($configPath)) {
        require $configPath;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded) {
    fwrite(STDERR, "PrestaShop config.inc.php not found\n");
    exit(1);
}

$module = Module::getInstanceByName('smartvitrines');
if (!$module) {
    fwrite(STDERR, "Module smartvitrines not found\n");
    exit(1);
}

$register = ['displayOrderConfirmation', 'displayFooterProduct'];
$unregister = ['actionValidateOrder'];

foreach ($register as $hook) {
    echo ($module->registerHook($hook) ? 'OK' : 'FAIL') . " registerHook({$hook})\n";
}

foreach ($unregister as $hook) {
    echo ($module->unregisterHook($hook) ? 'OK' : 'FAIL') . " unregisterHook({$hook})\n";
}

echo "Module version: {$module->version}\n";
