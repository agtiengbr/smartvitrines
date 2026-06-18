<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/SmartvitrinesOrderExporter.php';

class smartvitrines extends Module
{
    public const CONFIG_PUBLIC_KEY = 'SMARTVITRINES_PUBLIC_KEY';
    public const CONFIG_SECRET_KEY = 'SMARTVITRINES_SECRET_KEY';
    public const CONFIG_API_URL = 'SMARTVITRINES_API_URL';
    public const CONFIG_SKU_FIELD = 'SMARTVITRINES_SKU_FIELD';

    public function __construct()
    {
        $this->name = 'smartvitrines';
        $this->tab = 'analytics_stats';
        $this->version = '1.0.0';
        $this->author = 'SmartVitrines';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->l('SmartVitrines');
        $this->description = $this->l('Recomendações comportamentais — SDK, conversão e endpoint de pedido para o worker SmartVitrines.');
        $this->confirmUninstall = $this->l('Remover configurações SmartVitrines?');
    }

    public function install(): bool
    {
        return parent::install()
            && Configuration::updateValue(self::CONFIG_PUBLIC_KEY, '')
            && Configuration::updateValue(self::CONFIG_SECRET_KEY, '')
            && Configuration::updateValue(self::CONFIG_API_URL, '')
            && Configuration::updateValue(self::CONFIG_SKU_FIELD, 'reference')
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayOrderConfirmation');
    }

    public function uninstall(): bool
    {
        return Configuration::deleteByName(self::CONFIG_PUBLIC_KEY)
            && Configuration::deleteByName(self::CONFIG_SECRET_KEY)
            && Configuration::deleteByName(self::CONFIG_API_URL)
            && Configuration::deleteByName(self::CONFIG_SKU_FIELD)
            && parent::uninstall();
    }

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitSmartvitrinesConfig')) {
            $publicKey = trim((string) Tools::getValue(self::CONFIG_PUBLIC_KEY));
            $secretKey = trim((string) Tools::getValue(self::CONFIG_SECRET_KEY));
            $apiUrl = rtrim(trim((string) Tools::getValue(self::CONFIG_API_URL)), '/');
            $skuField = trim((string) Tools::getValue(self::CONFIG_SKU_FIELD));

            if ($publicKey === '' || $secretKey === '') {
                $output .= $this->displayError($this->l('Public Key e Secret Key são obrigatórios.'));
            } else {
                Configuration::updateValue(self::CONFIG_PUBLIC_KEY, $publicKey);
                Configuration::updateValue(self::CONFIG_SECRET_KEY, $secretKey);
                Configuration::updateValue(self::CONFIG_API_URL, $apiUrl);
                Configuration::updateValue(self::CONFIG_SKU_FIELD, $skuField !== '' ? $skuField : 'reference');
                $output .= $this->displayConfirmation($this->l('Configurações salvas.'));
            }
        }

        return $output . $this->renderConfigForm();
    }

    public function hookDisplayHeader(array $params): string
    {
        $publicKey = (string) Configuration::get(self::CONFIG_PUBLIC_KEY);
        if ($publicKey === '') {
            return '';
        }

        $apiUrl = $this->getApiBaseUrl();
        $scriptUrl = $apiUrl . '/sdk/v1/sv.min.js';

        $this->context->smarty->assign([
            'smartvitrines_script_url' => $scriptUrl,
            'smartvitrines_public_key' => $publicKey,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/header.tpl');
    }

    /**
     * Conversão via SDK no browser (session_id vem do cookie do SDK).
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayOrderConfirmation(array $params): string
    {
        $publicKey = (string) Configuration::get(self::CONFIG_PUBLIC_KEY);
        if ($publicKey === '') {
            return '';
        }

        if (!isset($params['order']) || !($params['order'] instanceof Order)) {
            return '';
        }

        /** @var Order $order */
        $order = $params['order'];

        $this->context->smarty->assign([
            'smartvitrines_order_ref' => (string) $order->id,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order-confirmation.tpl');
    }

    public function validateWorkerAuth(): bool
    {
        $secret = (string) Configuration::get(self::CONFIG_SECRET_KEY);
        if ($secret === '') {
            return false;
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (is_string($header) && preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return hash_equals($secret, $matches[1]);
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildOrderPayload(int $orderId): ?array
    {
        $exporter = new SmartvitrinesOrderExporter(
            (string) Configuration::get(self::CONFIG_SKU_FIELD)
        );

        return $exporter->export($orderId);
    }

    public function getApiBaseUrl(): string
    {
        $configured = rtrim(trim((string) Configuration::get(self::CONFIG_API_URL)), '/');
        if ($configured !== '') {
            return $configured;
        }

        return 'https://api.smartvitrines.com';
    }

    private function renderConfigForm(): string
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('SmartVitrines'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Public Key'),
                        'name' => self::CONFIG_PUBLIC_KEY,
                        'required' => true,
                        'desc' => $this->l('Chave pública do tenant (pk_live_...).'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Secret Key'),
                        'name' => self::CONFIG_SECRET_KEY,
                        'required' => true,
                        'desc' => $this->l('Mesma secret_key do tenant — auth do worker no endpoint de pedido.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API URL'),
                        'name' => self::CONFIG_API_URL,
                        'desc' => $this->l('Base da API SmartVitrines. Vazio = produção. Dev: http://host.docker.internal:18080'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Campo SKU'),
                        'name' => self::CONFIG_SKU_FIELD,
                        'options' => [
                            'query' => [
                                ['id' => 'reference', 'name' => $this->l('Referência (reference)')],
                                ['id' => 'ean13', 'name' => $this->l('EAN-13')],
                                ['id' => 'upc', 'name' => $this->l('UPC')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Salvar'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSmartvitrinesConfig';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->fields_value = [
            self::CONFIG_PUBLIC_KEY => Configuration::get(self::CONFIG_PUBLIC_KEY),
            self::CONFIG_SECRET_KEY => Configuration::get(self::CONFIG_SECRET_KEY),
            self::CONFIG_API_URL => Configuration::get(self::CONFIG_API_URL),
            self::CONFIG_SKU_FIELD => Configuration::get(self::CONFIG_SKU_FIELD) ?: 'reference',
        ];

        return $helper->generateForm([$fieldsForm]);
    }
}
