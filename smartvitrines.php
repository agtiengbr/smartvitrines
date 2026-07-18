<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/SmartvitrinesProductSkuResolver.php';
require_once dirname(__FILE__) . '/classes/SmartvitrinesOrderExporter.php';
require_once dirname(__FILE__) . '/classes/SmartvitrinesApiClient.php';
if (version_compare(_PS_VERSION_, '1.7.0.0', '>=')) {
    require_once dirname(__FILE__) . '/classes/SmartvitrinesRecommendationsPresenter.php';
} else {
    require_once dirname(__FILE__) . '/classes/SmartvitrinesRecommendationsPresenterPs16.php';
}

class smartvitrines extends Module
{
    public const CONFIG_PUBLIC_KEY = 'SMARTVITRINES_PUBLIC_KEY';
    public const CONFIG_SECRET_KEY = 'SMARTVITRINES_SECRET_KEY';
    public const CONFIG_API_URL = 'SMARTVITRINES_API_URL';
    public const CONFIG_SKU_FIELD = 'SMARTVITRINES_SKU_FIELD';
    public const CONFIG_THEME_LAYOUT = 'SMARTVITRINES_THEME_LAYOUT';
    public const CONFIG_PDP_LIMIT = 'SMARTVITRINES_PDP_LIMIT';
    public const CONFIG_CART_LIMIT = 'SMARTVITRINES_CART_LIMIT';
    public const THEME_LAYOUT_HUMMINGBIRD = 'hummingbird';
    public const THEME_LAYOUT_CLASSIC = 'classic';
    public const DEFAULT_PDP_LIMIT = 4;
    public const DEFAULT_CART_LIMIT = 4;
    public const BO_LIMIT_MAX = 100;

    public function __construct()
    {
        $this->name = 'smartvitrines';
        $this->tab = 'analytics_stats';
        $this->version = '1.6.1';
        $this->author = 'SmartVitrines';
        $this->need_instance = 0;
        $this->bootstrap = version_compare(_PS_VERSION_, '1.7.0.0', '>=');
        $this->ps_versions_compliancy = ['min' => '1.6.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->l('SmartVitrines');
        $this->description = $this->l('Recomendações comportamentais — SDK, conversão e endpoint de pedido para o worker SmartVitrines.');
        $this->confirmUninstall = $this->l('Remover configurações SmartVitrines?');
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateValue(self::CONFIG_PUBLIC_KEY, '')
            && Configuration::updateValue(self::CONFIG_SECRET_KEY, '')
            && Configuration::updateValue(self::CONFIG_API_URL, '')
            && Configuration::updateValue(self::CONFIG_SKU_FIELD, 'reference')
            && Configuration::updateValue(self::CONFIG_THEME_LAYOUT, self::THEME_LAYOUT_HUMMINGBIRD)
            && Configuration::updateValue(self::CONFIG_PDP_LIMIT, (string) self::DEFAULT_PDP_LIMIT)
            && Configuration::updateValue(self::CONFIG_CART_LIMIT, (string) self::DEFAULT_CART_LIMIT)
            && $this->registerHook('displayHeader')
            && $this->registerRecommendationHooks()
            && $this->registerAddToCartHooks()
            && $this->registerHook('displayOrderConfirmation');
    }

    public function uninstall()
    {
        return Configuration::deleteByName(self::CONFIG_PUBLIC_KEY)
            && Configuration::deleteByName(self::CONFIG_SECRET_KEY)
            && Configuration::deleteByName(self::CONFIG_API_URL)
            && Configuration::deleteByName(self::CONFIG_SKU_FIELD)
            && Configuration::deleteByName(self::CONFIG_THEME_LAYOUT)
            && Configuration::deleteByName(self::CONFIG_PDP_LIMIT)
            && Configuration::deleteByName(self::CONFIG_CART_LIMIT)
            && parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitSmartvitrinesConfig')) {
            $publicKey = trim((string) Tools::getValue(self::CONFIG_PUBLIC_KEY));
            $secretKey = trim((string) Tools::getValue(self::CONFIG_SECRET_KEY));
            $apiUrl = rtrim(trim((string) Tools::getValue(self::CONFIG_API_URL)), '/');
            $skuField = trim((string) Tools::getValue(self::CONFIG_SKU_FIELD));
            $themeLayout = (string) Tools::getValue(self::CONFIG_THEME_LAYOUT);
            $pdpLimit = $this->normalizeConfiguredLimit(Tools::getValue(self::CONFIG_PDP_LIMIT));
            $cartLimit = $this->normalizeConfiguredLimit(Tools::getValue(self::CONFIG_CART_LIMIT));

            if ($publicKey === '' || $secretKey === '') {
                $output .= $this->displayError($this->l('Public Key e Secret Key são obrigatórios.'));
            } else {
                Configuration::updateValue(self::CONFIG_PUBLIC_KEY, $publicKey);
                Configuration::updateValue(self::CONFIG_SECRET_KEY, $secretKey);
                Configuration::updateValue(self::CONFIG_API_URL, $apiUrl);
                Configuration::updateValue(self::CONFIG_SKU_FIELD, $skuField !== '' ? $skuField : 'reference');
                Configuration::updateValue(
                    self::CONFIG_THEME_LAYOUT,
                    $this->normalizeThemeLayout($themeLayout)
                );
                Configuration::updateValue(self::CONFIG_PDP_LIMIT, (string) $pdpLimit);
                Configuration::updateValue(self::CONFIG_CART_LIMIT, (string) $cartLimit);
                $output .= $this->displayConfirmation($this->l('Configurações salvas.'));
            }
        }

        return $output . $this->renderConfigForm();
    }

    public function hookDisplayHeader($params)
    {
        $publicKey = (string) Configuration::get(self::CONFIG_PUBLIC_KEY);
        if ($publicKey === '') {
            return '';
        }

        $scriptUrl = $this->getSdkScriptUrl();

        $this->context->smarty->assign([
            'smartvitrines_script_url' => $scriptUrl,
            'smartvitrines_public_key' => $publicKey,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/header.tpl');
    }

    /**
     * PS 1.6 — recomendações na página do produto.
     *
     * @param array<string, mixed> $params
     */
    public function hookProductFooter($params)
    {
        return $this->renderProductRecommendations($params);
    }

    /**
     * Bloco de recomendações na página do produto (renderização server-side).
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayFooterProduct($params)
    {
        return $this->renderProductRecommendations($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderProductRecommendations($params)
    {
        $publicKey = (string) Configuration::get(self::CONFIG_PUBLIC_KEY);
        $product = isset($params['product']) ? $params['product'] : null;
        if ($publicKey === '' || $product === null) {
            return '';
        }

        if (
            !is_array($product)
            && !($product instanceof \ArrayAccess)
            && !($product instanceof Product)
        ) {
            return '';
        }

        $product = $this->normalizeHookProduct($product);
        $skuField = (string) (Configuration::get(self::CONFIG_SKU_FIELD) ?: 'reference');
        $html = '';

        $trackSku = $this->resolveProductSku($product, $skuField);
        if ($trackSku !== '') {
            $this->smarty->assign([
                'smartvitrines_track_sku' => $trackSku,
            ]);
            $html .= $this->display(__FILE__, 'views/templates/hook/product-track.tpl');
        }

        $presenter = $this->createRecommendationsPresenter();
        $sessionId = $this->resolveSdkSessionId();
        $result = $presenter->present(
            $publicKey,
            $this->getApiBaseUrl(),
            $skuField,
            $product,
            $this->l('Você também pode se interessar por:'),
            $this->getConfiguredLimit(self::CONFIG_PDP_LIMIT),
            $sessionId
        );

        if ($result['products'] === []) {
            return $html;
        }

        $this->smarty->assign([
            'smartvitrines_products' => $result['products'],
            'smartvitrines_title' => $result['title'],
            'smartvitrines_sku_map' => $result['sku_map'],
        ]);

        return $html . $this->renderRecommendationsBlock($result);
    }

    /**
     * PS 1.6 — recomendações no carrinho.
     *
     * @param array<string, mixed> $params
     */
    public function hookShoppingCartExtra($params)
    {
        return $this->renderCartRecommendations($params);
    }

    /**
     * Bloco de recomendações na página do carrinho (renderização server-side).
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayShoppingCart($params)
    {
        return $this->renderCartRecommendations($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderCartRecommendations($params)
    {
        $publicKey = (string) Configuration::get(self::CONFIG_PUBLIC_KEY);
        if ($publicKey === '') {
            return '';
        }

        $cart = isset($params['cart']) ? $params['cart'] : ($this->context->cart ?? null);
        if (!$cart instanceof Cart) {
            return '';
        }

        $skuField = (string) (Configuration::get(self::CONFIG_SKU_FIELD) ?: 'reference');
        $originSkus = $this->collectCartSkus($cart, $skuField);
        if ($originSkus === []) {
            return '';
        }

        $presenter = $this->createRecommendationsPresenter();
        $sessionId = $this->resolveSdkSessionId();
        $result = $presenter->presentForSkus(
            $publicKey,
            $this->getApiBaseUrl(),
            $skuField,
            $originSkus,
            $this->collectCartProductIds($cart),
            $this->l('Complete sua compra com:'),
            $this->getConfiguredLimit(self::CONFIG_CART_LIMIT),
            $sessionId
        );

        if ($result['products'] === []) {
            return '';
        }

        $this->smarty->assign([
            'smartvitrines_products' => $result['products'],
            'smartvitrines_title' => $result['title'],
            'smartvitrines_sku_map' => $result['sku_map'],
        ]);

        return $this->renderRecommendationsBlock($result);
    }

    /**
     * @param array{title: string, products: list<array<string, mixed>>, sku_map: array<string, string>} $result
     */
    private function renderRecommendationsBlock(array $result)
    {
        $html = $this->display(__FILE__, $this->getRecommendationsTemplatePath());

        if ($result['sku_map'] === []) {
            return $html;
        }

        $this->smarty->assign([
            'smartvitrines_sku_map' => $result['sku_map'],
        ]);

        return $html . $this->display(__FILE__, 'views/templates/hook/recommendations-click-track.tpl');
    }

    /**
     * @param Cart $cart
     *
     * @return list<string>
     */
    private function collectCartSkus($cart, $skuField)
    {
        $skus = [];
        foreach ($cart->getProducts() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sku = $this->resolveCartLineSku($row, $skuField);
            if ($sku !== '' && !in_array($sku, $skus, true)) {
                $skus[] = $sku;
            }
        }

        return $skus;
    }

    /**
     * @param Cart $cart
     *
     * @return list<int>
     */
    private function collectCartProductIds($cart)
    {
        $productIds = [];
        foreach ($cart->getProducts() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['id_product'] ?? 0);
            if ($id > 0) {
                $productIds[$id] = $id;
            }
        }

        return array_values($productIds);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveCartLineSku(array $row, $skuField)
    {
        return SmartvitrinesProductSkuResolver::extractSku((string) $skuField, $row);
    }

    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $product
     */
    private function resolveProductSku($product, $skuField)
    {
        if (!is_array($product)) {
            return '';
        }

        return SmartvitrinesProductSkuResolver::extractSku((string) $skuField, $product);
    }

    private function getRecommendationsTemplatePath()
    {
        if (!version_compare(_PS_VERSION_, '1.7.0.0', '>=')) {
            return 'views/templates/hook/product-recommendations-ps16.tpl';
        }

        if ($this->getThemeLayout() === self::THEME_LAYOUT_CLASSIC) {
            return 'views/templates/hook/product-recommendations-classic.tpl';
        }

        return 'views/templates/hook/product-recommendations-hummingbird.tpl';
    }

    private function registerRecommendationHooks()
    {
        if (version_compare(_PS_VERSION_, '1.7.0.0', '>=')) {
            return $this->registerHook('displayFooterProduct')
                && $this->registerHook('displayShoppingCart');
        }

        return $this->registerHook('productFooter')
            && $this->registerHook('shoppingCartExtra');
    }

    /**
     * PS 1.6: actionBeforeCartUpdateQty; PS ≥ 1.7: actionCartUpdateQuantityBefore
     * (mesmo call site em Cart::updateQty). Idempotente (upgrade / re-registro).
     */
    public function registerAddToCartHooks()
    {
        if (version_compare(_PS_VERSION_, '1.7.0.0', '>=')) {
            return $this->registerHook('actionCartUpdateQuantityBefore');
        }

        return $this->registerHook('actionBeforeCartUpdateQty');
    }

    /**
     * PS ≥ 1.7 — quantidade no carrinho antes do update.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionCartUpdateQuantityBefore($params)
    {
        $this->trackAddToCartFromQtyHook($params);
    }

    /**
     * PS 1.6 — quantidade no carrinho antes do update.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionBeforeCartUpdateQty($params)
    {
        $this->trackAddToCartFromQtyHook($params);
    }

    /**
     * Emite product_add_to_cart na API quando operator === up.
     *
     * @param array<string, mixed> $params
     */
    private function trackAddToCartFromQtyHook($params)
    {
        if (!is_array($params)) {
            return;
        }

        if (($params['operator'] ?? '') !== 'up' || (int) ($params['quantity'] ?? 0) <= 0) {
            return;
        }

        $publicKey = (string) Configuration::get(self::CONFIG_PUBLIC_KEY);
        if ($publicKey === '') {
            return;
        }

        $sessionId = $this->resolveSdkSessionId();
        if ($sessionId === null) {
            return;
        }

        $sku = $this->resolveSkuFromCartQtyParams($params);
        if ($sku === '') {
            return;
        }

        $client = new SmartvitrinesApiClient($this->getApiBaseUrl());
        $client->postAddToCart($publicKey, $sessionId, $sku, $this->resolveSdkVisitorUid());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveSkuFromCartQtyParams(array $params)
    {
        $product = $params['product'] ?? null;
        if (!$product instanceof Product) {
            return '';
        }

        $idProduct = (int) $product->id;
        if ($idProduct <= 0) {
            return '';
        }

        $idProductAttribute = (int) ($params['id_product_attribute'] ?? 0);
        $row = [
            'id_product' => $idProduct,
            'id_product_attribute' => $idProductAttribute,
            'reference' => (string) $product->reference,
            'ean13' => (string) $product->ean13,
            'upc' => (string) $product->upc,
        ];

        if ($idProductAttribute > 0) {
            $combination = new Combination($idProductAttribute);
            if (Validate::isLoadedObject($combination)) {
                if ((string) $combination->reference !== '') {
                    $row['reference'] = (string) $combination->reference;
                }
                if ((string) $combination->ean13 !== '') {
                    $row['ean13'] = (string) $combination->ean13;
                }
                if ((string) $combination->upc !== '') {
                    $row['upc'] = (string) $combination->upc;
                }
            }
        }

        $skuField = (string) (Configuration::get(self::CONFIG_SKU_FIELD) ?: 'reference');

        return SmartvitrinesProductSkuResolver::extractSku($skuField, $row);
    }

    /**
     * @return SmartvitrinesRecommendationsPresenter|SmartvitrinesRecommendationsPresenterPs16
     */
    private function createRecommendationsPresenter()
    {
        if (version_compare(_PS_VERSION_, '1.7.0.0', '>=')) {
            return new SmartvitrinesRecommendationsPresenter($this->context);
        }

        return new SmartvitrinesRecommendationsPresenterPs16($this->context);
    }

    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed>|Product $product
     *
     * @return array<string, mixed>
     */
    private function normalizeHookProduct($product)
    {
        if ($product instanceof Product) {
            $idProduct = (int) $product->id;
            $defaultAttr = (int) Product::getDefaultAttribute($idProduct);

            return [
                'id_product' => $idProduct,
                'id_product_attribute' => $defaultAttr > 0 ? $defaultAttr : 0,
                'reference' => (string) $product->reference,
                'reference_to_display' => (string) $product->reference,
                'ean13' => (string) $product->ean13,
                'upc' => (string) $product->upc,
            ];
        }

        if (is_array($product) || $product instanceof \ArrayAccess) {
            return [
                'id_product' => (int) ($product['id_product'] ?? $product['id'] ?? 0),
                'id_product_attribute' => (int) ($product['id_product_attribute'] ?? $product['product_attribute_id'] ?? 0),
                'reference' => (string) ($product['reference'] ?? ''),
                'reference_to_display' => (string) ($product['reference_to_display'] ?? $product['reference'] ?? ''),
                'ean13' => (string) ($product['ean13'] ?? ''),
                'upc' => (string) ($product['upc'] ?? ''),
            ];
        }

        return [];
    }

    private function getThemeLayout()
    {
        return $this->normalizeThemeLayout((string) Configuration::get(self::CONFIG_THEME_LAYOUT));
    }

    private function normalizeThemeLayout($layout)
    {
        return $layout === self::THEME_LAYOUT_CLASSIC
            ? self::THEME_LAYOUT_CLASSIC
            : self::THEME_LAYOUT_HUMMINGBIRD;
    }

    private function getConfiguredLimit($configKey)
    {
        $stored = Configuration::get($configKey);
        if ($stored === false || $stored === null || $stored === '') {
            if ($configKey === self::CONFIG_CART_LIMIT) {
                return self::DEFAULT_CART_LIMIT;
            }

            return self::DEFAULT_PDP_LIMIT;
        }

        return $this->normalizeConfiguredLimit($stored);
    }

    private function normalizeConfiguredLimit($value)
    {
        $limit = (int) $value;

        return max(1, min(self::BO_LIMIT_MAX, $limit));
    }

    /**
     * session_id do SDK (cookie sv_session_id) para atribuição de recomendações served.
     *
     * @return string|null
     */
    private function resolveSdkSessionId()
    {
        return $this->resolveSdkUuidCookie('sv_session_id');
    }

    /**
     * visitor_uid do SDK (cookie sv_visitor_uid) para eventos server-side.
     *
     * @return string|null
     */
    private function resolveSdkVisitorUid()
    {
        return $this->resolveSdkUuidCookie('sv_visitor_uid');
    }

    /**
     * @return string|null
     */
    private function resolveSdkUuidCookie($cookieName)
    {
        $value = isset($_COOKIE[$cookieName]) ? trim((string) $_COOKIE[$cookieName]) : '';
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Conversão via SDK no browser (session_id vem do cookie do SDK).
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayOrderConfirmation($params)
    {
        $publicKey = (string) Configuration::get(self::CONFIG_PUBLIC_KEY);
        if ($publicKey === '') {
            return '';
        }

        $order = $this->resolveOrderConfirmationOrder($params);
        if (!$order instanceof Order) {
            return '';
        }

        $this->context->smarty->assign([
            'smartvitrines_order_ref' => (string) $order->id,
            'smartvitrines_script_url' => $this->getSdkScriptUrl(),
            'smartvitrines_public_key' => $publicKey,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order-confirmation.tpl');
    }

    /**
     * PS 1.6 passes objOrder; PS 1.7+ passes order.
     *
     * @param array<string, mixed> $params
     */
    private function resolveOrderConfirmationOrder($params)
    {
        foreach (['order', 'objOrder'] as $key) {
            if (isset($params[$key]) && $params[$key] instanceof Order) {
                return $params[$key];
            }
        }

        return null;
    }

    public function validateWorkerAuth()
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
    public function buildOrderPayload($orderId)
    {
        $exporter = new SmartvitrinesOrderExporter(
            (string) Configuration::get(self::CONFIG_SKU_FIELD)
        );

        return $exporter->export($orderId);
    }

    public function getApiBaseUrl()
    {
        $configured = rtrim(trim((string) Configuration::get(self::CONFIG_API_URL)), '/');
        if ($configured !== '') {
            return $configured;
        }

        return 'https://api.analytics.agti.eng.br';
    }

    public function getSdkScriptUrl()
    {
        $configured = rtrim(trim((string) Configuration::get(self::CONFIG_API_URL)), '/');
        if ($configured !== '') {
            return $configured . '/v1/sdk.min.js';
        }

        return 'https://analytics.agti.eng.br/v1/sdk.min.js';
    }

    private function renderConfigForm()
    {
        $inputs = [
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
                'desc' => $this->l('Base da API SmartVitrines (ex.: https://api.analytics.agti.eng.br). Deixe vazio para usar o endpoint de produção padrão.'),
            ],
            [
                'type' => 'select',
                'label' => $this->l('Identificador do Produto'),
                'name' => self::CONFIG_SKU_FIELD,
                'options' => [
                    'query' => [
                        ['id' => 'reference', 'name' => $this->l('Referência (reference)')],
                        ['id' => 'id', 'name' => $this->l('ID')],
                        ['id' => 'ean13', 'name' => $this->l('EAN-13')],
                        ['id' => 'upc', 'name' => $this->l('UPC')],
                    ],
                    'id' => 'id',
                    'name' => 'name',
                ],
            ],
            [
                'type' => 'html',
                'name' => 'smartvitrines_sku_id_hint',
                'html_content' => '<div class="alert alert-info">'
                    . $this->l('Ao escolher ID, o valor enviado à API deve ser o mesmo g:id do feed XML do Google Shopping.')
                    . '</div>',
            ],
        ];

        if (version_compare(_PS_VERSION_, '1.7.0.0', '>=')) {
            $inputs[] = [
                'type' => 'select',
                'label' => $this->l('Layout das recomendações'),
                'name' => self::CONFIG_THEME_LAYOUT,
                'desc' => $this->l('Escolha o markup compatível com o tema ativo da loja.'),
                'options' => [
                    'query' => [
                        [
                            'id' => self::THEME_LAYOUT_HUMMINGBIRD,
                            'name' => $this->l('Hummingbird (module-products)'),
                        ],
                        [
                            'id' => self::THEME_LAYOUT_CLASSIC,
                            'name' => $this->l('Classic (product-accessories)'),
                        ],
                    ],
                    'id' => 'id',
                    'name' => 'name',
                ],
            ];
        }

        $inputs[] = [
            'type' => 'text',
            'label' => $this->l('Limite na página do produto (PDP)'),
            'name' => self::CONFIG_PDP_LIMIT,
            'desc' => $this->l('Quantidade exibida na PDP. Teto final na API: SV_RECOMMENDATIONS_MAX_LIMIT (default 20).'),
        ];
        $inputs[] = [
            'type' => 'text',
            'label' => $this->l('Limite no carrinho'),
            'name' => self::CONFIG_CART_LIMIT,
            'desc' => $this->l('Quantidade exibida no carrinho. Teto final na API: SV_RECOMMENDATIONS_MAX_LIMIT (default 20).'),
        ];

        if (version_compare(_PS_VERSION_, '1.7.0.0', '>=')) {
            $fieldsForm = [
                'form' => [
                    'legend' => [
                        'title' => $this->l('SmartVitrines'),
                        'icon' => 'icon-cogs',
                    ],
                    'input' => $inputs,
                    'submit' => [
                        'title' => $this->l('Salvar'),
                    ],
                ],
            ];
        } else {
            $fieldsForm = [
                'legend' => [
                    'title' => $this->l('SmartVitrines'),
                    'icon' => 'icon-cogs',
                ],
                'input' => $inputs,
                'submit' => [
                    'title' => $this->l('Salvar'),
                ],
            ];
        }

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSmartvitrinesConfig';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        if (!version_compare(_PS_VERSION_, '1.7.0.0', '>=')) {
            $helper->module = $this->name;
        }

        $helper->fields_value = [
            self::CONFIG_PUBLIC_KEY => Configuration::get(self::CONFIG_PUBLIC_KEY),
            self::CONFIG_SECRET_KEY => Configuration::get(self::CONFIG_SECRET_KEY),
            self::CONFIG_API_URL => Configuration::get(self::CONFIG_API_URL),
            self::CONFIG_SKU_FIELD => Configuration::get(self::CONFIG_SKU_FIELD) ?: 'reference',
            self::CONFIG_THEME_LAYOUT => $this->getThemeLayout(),
            self::CONFIG_PDP_LIMIT => (string) $this->getConfiguredLimit(self::CONFIG_PDP_LIMIT),
            self::CONFIG_CART_LIMIT => (string) $this->getConfiguredLimit(self::CONFIG_CART_LIMIT),
        ];

        return $helper->generateForm([$fieldsForm]);
    }
}
