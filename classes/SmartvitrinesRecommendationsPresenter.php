<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;

final class SmartvitrinesRecommendationsPresenter
{
    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $product
     *
     * @return array{title: string, products: list<array<string, mixed>>}
     */
    public function present($publicKey, $apiBaseUrl, $skuField, $product, $title, $limit, $sessionId = null)
    {
        $empty = ['title' => (string) $title, 'products' => []];
        $product = $this->normalizeProduct($product);
        $displayLimit = max(1, (int) $limit);

        $currentProductId = (int) ($product['id_product'] ?? 0);
        $sku = $this->extractSku((string) $skuField, $product);
        if ($sku === '') {
            return $empty;
        }

        $client = new SmartvitrinesApiClient($apiBaseUrl);
        $recommendedSkus = $client->getRecommendations((string) $publicKey, $sku, $displayLimit, $sessionId);
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
     * Recomendações a partir de vários SKUs (ex.: itens do carrinho).
     *
     * @param list<string> $originSkus
     * @param list<int> $excludeProductIds
     *
     * @return array{title: string, products: list<array<string, mixed>>}
     */
    public function presentForSkus($publicKey, $apiBaseUrl, $skuField, array $originSkus, array $excludeProductIds, $title, $limit, $sessionId = null)
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
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $product
     *
     * @return array<string, mixed>
     */
    private function normalizeProduct($product)
    {
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

                return trim($reference);
        }
    }

    private function resolveProductId($skuField, $sku)
    {
        switch ($skuField) {
            case 'ean13':
                return (int) Product::getIdByEan13($sku);
            case 'upc':
                return $this->resolveProductIdByUpc($sku);
            default:
                $id = (int) Product::getIdByReference($sku);
                if ($id > 0) {
                    return $id;
                }

                return $this->resolveProductIdByCombinationReference($sku);
        }
    }

    private function resolveProductIdByCombinationReference($reference)
    {
        $reference = (string) $reference;
        if ($reference === '') {
            return 0;
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
        $assembler = new ProductAssembler($this->context);
        $presenterFactory = new ProductPresenterFactory($this->context);
        $presentationSettings = $presenterFactory->getPresentationSettings();
        $presentationSettings->showPrices = true;

        $presenter = new ProductListingPresenter(
            new ImageRetriever($this->context->link),
            $this->context->link,
            new PriceFormatter(),
            new ProductColorsRetriever(),
            $this->context->getTranslator()
        );

        $presented = [];
        foreach ($productIds as $productId) {
            $rawProduct = $assembler->assembleProduct(['id_product' => $productId]);
            $presented[] = $presenter->present(
                $presentationSettings,
                $rawProduct,
                $this->context->language
            );
        }

        return $presented;
    }
}
