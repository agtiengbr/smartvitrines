<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

final class SmartvitrinesRecommendationsPresenterPs16
{
    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed>|Product $product
     *
     * @return array{title: string, products: list<array<string, mixed>>}
     */
    public function present($publicKey, $apiBaseUrl, $skuField, $product, $title, $limit)
    {
        $empty = ['title' => (string) $title, 'products' => []];
        $product = $this->normalizeProduct($product);
        $displayLimit = max(1, (int) $limit);

        $currentProductId = (int) ($product['id_product'] ?? 0);
        $sku = $this->extractSku((string) $skuField, $product);

        $client = new SmartvitrinesApiClient($apiBaseUrl);
        $recommendedSkus = $client->getRecommendations((string) $publicKey, $sku, $displayLimit);
        if ($recommendedSkus === []) {
            return $empty;
        }

        $productIds = [];
        foreach ($recommendedSkus as $recommendedSku) {
            if (count($productIds) >= $displayLimit) {
                break;
            }

            $id = $this->resolveProductId((string) $skuField, $recommendedSku);
            if ($id <= 0 || $id === $currentProductId || isset($productIds[$id])) {
                continue;
            }

            if (!$this->isVisibleProduct($id)) {
                continue;
            }

            $productIds[$id] = $id;
        }

        if ($productIds === []) {
            return $empty;
        }

        return [
            'title' => (string) $title,
            'products' => $this->presentProducts(array_values($productIds)),
        ];
    }

    /**
     * @param list<string> $originSkus
     * @param list<int> $excludeProductIds
     *
     * @return array{title: string, products: list<array<string, mixed>>}
     */
    public function presentForSkus($publicKey, $apiBaseUrl, $skuField, array $originSkus, array $excludeProductIds, $title, $limit)
    {
        $empty = ['title' => (string) $title, 'products' => []];
        $originSkus = $this->normalizeSkuList($originSkus);
        if ($originSkus === []) {
            return $empty;
        }

        $displayLimit = max(1, (int) $limit);
        $client = new SmartvitrinesApiClient($apiBaseUrl);
        $recommendedSkus = $client->getRecommendations(
            (string) $publicKey,
            implode(',', $originSkus),
            $displayLimit
        );
        if ($recommendedSkus === []) {
            return $empty;
        }

        $excludeIds = [];
        foreach ($excludeProductIds as $productId) {
            $id = (int) $productId;
            if ($id > 0) {
                $excludeIds[$id] = true;
            }
        }

        $productIds = [];
        foreach ($recommendedSkus as $recommendedSku) {
            if (count($productIds) >= $displayLimit) {
                break;
            }

            $id = $this->resolveProductId((string) $skuField, $recommendedSku);
            if ($id <= 0 || isset($excludeIds[$id]) || isset($productIds[$id])) {
                continue;
            }

            if (!$this->isVisibleProduct($id)) {
                continue;
            }

            $productIds[$id] = $id;
        }

        if ($productIds === []) {
            return $empty;
        }

        return [
            'title' => (string) $title,
            'products' => $this->presentProducts(array_values($productIds)),
        ];
    }

    /**
     * @param list<string> $skus
     *
     * @return list<string>
     */
    private function normalizeSkuList(array $skus)
    {
        $normalized = [];
        foreach ($skus as $sku) {
            $value = trim((string) $sku);
            if ($value !== '' && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed>|Product $product
     *
     * @return array<string, mixed>
     */
    private function normalizeProduct($product)
    {
        if ($product instanceof Product) {
            return [
                'id_product' => (int) $product->id,
                'reference' => (string) $product->reference,
                'reference_to_display' => (string) $product->reference,
                'ean13' => (string) $product->ean13,
                'upc' => (string) $product->upc,
            ];
        }

        return [
            'id_product' => (int) ($product['id_product'] ?? $product['id'] ?? 0),
            'reference' => (string) ($product['reference'] ?? ''),
            'reference_to_display' => (string) ($product['reference_to_display'] ?? ''),
            'ean13' => (string) ($product['ean13'] ?? ''),
            'upc' => (string) ($product['upc'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    private function extractSku($skuField, array $product)
    {
        switch ($skuField) {
            case 'ean13':
                return trim((string) ($product['ean13'] ?? ''));
            case 'upc':
                return trim((string) ($product['upc'] ?? ''));
            default:
                $reference = (string) ($product['reference_to_display'] ?? $product['reference'] ?? '');
                $reference = trim($reference);
                if ($reference !== '') {
                    return $reference;
                }

                $idProduct = (int) ($product['id_product'] ?? 0);
                if ($idProduct <= 0) {
                    return '';
                }

                $defaultAttr = (int) Product::getDefaultAttribute($idProduct);

                return $defaultAttr > 0 ? (string) $defaultAttr : (string) $idProduct;
        }
    }

    private function resolveProductIdByAttributeId($attributeId)
    {
        $attributeId = (int) $attributeId;
        if ($attributeId <= 0) {
            return 0;
        }

        $sql = 'SELECT pa.id_product
                FROM ' . _DB_PREFIX_ . 'product_attribute pa
                INNER JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = pa.id_product
                ' . Shop::addSqlAssociation('product', 'p') . '
                WHERE pa.id_product_attribute = ' . $attributeId;

        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
    }

    private function resolveProductId($skuField, $sku)
    {
        switch ($skuField) {
            case 'ean13':
                return (int) Product::getIdByEan13($sku);
            case 'upc':
                return $this->resolveProductIdByUpc($sku);
            default:
                $id = $this->resolveProductIdByReference($sku);
                if ($id > 0) {
                    return $id;
                }

                return $this->resolveProductIdByCombinationReference($sku);
        }
    }

    private function resolveProductIdByReference($reference)
    {
        $reference = (string) $reference;
        if ($reference === '') {
            return 0;
        }

        $sql = 'SELECT p.id_product
                FROM ' . _DB_PREFIX_ . 'product p
                ' . Shop::addSqlAssociation('product', 'p') . '
                WHERE p.reference = \'' . pSQL($reference) . '\'';

        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
    }

    private function resolveProductIdByCombinationReference($reference)
    {
        $reference = (string) $reference;
        if ($reference === '') {
            return 0;
        }

        $id = $this->resolveProductIdByAttributeId((int) $reference);
        if ($id > 0) {
            return $id;
        }

        $sql = 'SELECT pa.id_product
                FROM ' . _DB_PREFIX_ . 'product_attribute pa
                INNER JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = pa.id_product
                ' . Shop::addSqlAssociation('product', 'p') . '
                WHERE pa.reference = \'' . pSQL($reference) . '\'';

        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
    }

    private function resolveProductIdByUpc($upc)
    {
        $upc = (string) $upc;
        if ($upc === '') {
            return 0;
        }

        $sql = 'SELECT p.id_product
                FROM ' . _DB_PREFIX_ . 'product p
                ' . Shop::addSqlAssociation('product', 'p') . '
                WHERE p.upc = \'' . pSQL($upc) . '\'';

        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
    }

    private function isVisibleProduct($productId)
    {
        $productId = (int) $productId;
        $product = new Product($productId, false, (int) $this->context->language->id);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return false;
        }

        return in_array($product->visibility, ['both', 'catalog'], true);
    }

    /**
     * @param list<int> $productIds
     *
     * @return list<array<string, mixed>>
     */
    private function presentProducts(array $productIds)
    {
        $langId = (int) $this->context->language->id;
        $presented = [];

        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if ($productId <= 0) {
                continue;
            }

            $product = new Product($productId, true, $langId);
            if (!Validate::isLoadedObject($product)) {
                continue;
            }

            $idProductAttribute = (int) Product::getDefaultAttribute($productId);
            $raw = Product::getProductProperties($langId, [
                'id_product' => $productId,
                'id_product_attribute' => $idProductAttribute > 0 ? $idProductAttribute : null,
                'out_of_stock' => (int) $product->out_of_stock,
            ]);
            if (!is_array($raw) || empty($raw['id_product'])) {
                continue;
            }

            $quantity = (int) StockAvailable::getQuantityAvailableByProduct(
                $productId,
                $idProductAttribute > 0 ? $idProductAttribute : null
            );

            $raw['quantity'] = $quantity;
            $raw['allow_oosp'] = (bool) Product::isAvailableWhenOutOfStock((int) $product->out_of_stock);
            $raw['available_for_order'] = (bool) $product->available_for_order;
            $raw['show_price'] = isset($raw['show_price']) ? (bool) $raw['show_price'] : true;

            $cover = Image::getCover($productId);
            $raw['id_image'] = is_array($cover) && isset($cover['id_image'])
                ? (int) $cover['id_image']
                : 0;
            $raw['legend'] = isset($raw['name']) ? (string) $raw['name'] : '';
            $raw['link'] = $this->context->link->getProductLink(
                $productId,
                isset($raw['link_rewrite']) ? $raw['link_rewrite'] : null,
                isset($raw['category']) ? $raw['category'] : null,
                null,
                $langId
            );

            $presented[] = $raw;
        }

        return $presented;
    }
}
