<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/SmartvitrinesProductSkuResolver.php';

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
     * @return array{title: string, products: list<array<string, mixed>>, sku_map: array<string, string>}
     */
    public function present($publicKey, $apiBaseUrl, $skuField, $product, $title, $limit, $sessionId = null)
    {
        $empty = ['title' => (string) $title, 'products' => [], 'sku_map' => []];
        $product = $this->normalizeProduct($product);
        $displayLimit = max(1, (int) $limit);

        $currentProductId = (int) ($product['id_product'] ?? 0);
        $sku = $this->extractSku((string) $skuField, $product);

        $client = new SmartvitrinesApiClient($apiBaseUrl);
        $recommendedSkus = $client->getRecommendations((string) $publicKey, $sku, $displayLimit, $sessionId);
        if ($recommendedSkus === []) {
            return $empty;
        }

        $productSkuMap = [];
        foreach ($recommendedSkus as $recommendedSku) {
            if (count($productSkuMap) >= $displayLimit) {
                break;
            }

            $id = $this->resolveProductId((string) $skuField, $recommendedSku);
            if ($id <= 0 || $id === $currentProductId || isset($productSkuMap[$id])) {
                continue;
            }

            if (!$this->isVisibleProduct($id)) {
                continue;
            }

            $productSkuMap[$id] = (string) $recommendedSku;
        }

        return $this->buildRecommendationResult((string) $title, $productSkuMap);
    }

    /**
     * @param list<string> $originSkus
     * @param list<int> $excludeProductIds
     *
     * @return array{title: string, products: list<array<string, mixed>>, sku_map: array<string, string>}
     */
    public function presentForSkus($publicKey, $apiBaseUrl, $skuField, array $originSkus, array $excludeProductIds, $title, $limit, $sessionId = null)
    {
        $empty = ['title' => (string) $title, 'products' => [], 'sku_map' => []];
        $originSkus = $this->normalizeSkuList($originSkus);
        if ($originSkus === []) {
            return $empty;
        }

        $displayLimit = max(1, (int) $limit);
        $client = new SmartvitrinesApiClient($apiBaseUrl);
        $recommendedSkus = $client->getRecommendations(
            (string) $publicKey,
            implode(',', $originSkus),
            $displayLimit,
            $sessionId
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

        $productSkuMap = [];
        foreach ($recommendedSkus as $recommendedSku) {
            if (count($productSkuMap) >= $displayLimit) {
                break;
            }

            $id = $this->resolveProductId((string) $skuField, $recommendedSku);
            if ($id <= 0 || isset($excludeIds[$id]) || isset($productSkuMap[$id])) {
                continue;
            }

            if (!$this->isVisibleProduct($id)) {
                continue;
            }

            $productSkuMap[$id] = (string) $recommendedSku;
        }

        return $this->buildRecommendationResult((string) $title, $productSkuMap);
    }

    /**
     * @param array<int, string> $productSkuMap
     *
     * @return array{title: string, products: list<array<string, mixed>>, sku_map: array<string, string>}
     */
    private function buildRecommendationResult($title, array $productSkuMap)
    {
        if ($productSkuMap === []) {
            return ['title' => (string) $title, 'products' => [], 'sku_map' => []];
        }

        $skuMap = [];
        foreach ($productSkuMap as $productId => $sku) {
            $skuMap[(string) $productId] = (string) $sku;
        }

        return [
            'title' => (string) $title,
            'products' => $this->presentProducts(array_keys($productSkuMap)),
            'sku_map' => $skuMap,
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
        return SmartvitrinesProductSkuResolver::extractSku((string) $skuField, $product);
    }

    private function resolveProductId($skuField, $sku)
    {
        return SmartvitrinesProductSkuResolver::resolveProductId((string) $skuField, (string) $sku);
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
            $row = [
                'id_product' => $productId,
                'id_product_attribute' => $idProductAttribute > 0 ? $idProductAttribute : null,
                'out_of_stock' => (int) $product->out_of_stock,
                'id_category_default' => (int) $product->id_category_default,
                'link_rewrite' => (string) $product->link_rewrite,
                'ean13' => (string) $product->ean13,
                'name' => (string) $product->name,
                'description_short' => (string) $product->description_short,
            ];
            $raw = Product::getProductProperties($langId, $row, $this->context);
            if (!is_array($raw) || empty($raw['id_product'])) {
                continue;
            }

            if (empty($raw['name'])) {
                $raw['name'] = (string) $product->name;
            }
            if (empty($raw['link_rewrite'])) {
                $raw['link_rewrite'] = (string) $product->link_rewrite;
            }
            if (empty($raw['description_short'])) {
                $raw['description_short'] = (string) $product->description_short;
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
