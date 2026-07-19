<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/SmartvitrinesProductSkuResolver.php';

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
     * @return array{title: string, products: list<array<string, mixed>>, sku_map: array<string, string>}
     */
    public function present($publicKey, $apiBaseUrl, $skuField, $product, $title, $limit, $sessionId = null, $pageviewId = null)
    {
        $empty = ['title' => (string) $title, 'products' => [], 'sku_map' => []];
        $product = $this->normalizeProduct($product);
        $displayLimit = max(1, (int) $limit);

        $currentProductId = (int) ($product['id_product'] ?? 0);
        $sku = $this->extractSku((string) $skuField, $product);
        if ($sku === '') {
            return $empty;
        }

        $client = new SmartvitrinesApiClient($apiBaseUrl);
        $recommendedSkus = $client->getRecommendations((string) $publicKey, $sku, $displayLimit, $sessionId, $pageviewId);
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
     * Recomendações a partir de vários SKUs (ex.: itens do carrinho).
     *
     * @param list<string> $originSkus
     * @param list<int> $excludeProductIds
     *
     * @return array{title: string, products: list<array<string, mixed>>, sku_map: array<string, string>}
     */
    public function presentForSkus($publicKey, $apiBaseUrl, $skuField, array $originSkus, array $excludeProductIds, $title, $limit, $sessionId = null, $pageviewId = null)
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
            $sessionId,
            $pageviewId
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
