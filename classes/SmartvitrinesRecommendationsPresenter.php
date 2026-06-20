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
    private const DISPLAY_LIMIT = 4;
    private const API_FETCH_LIMIT = 12;

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
    public function present($publicKey, $apiBaseUrl, $skuField, $product, $title)
    {
        $empty = ['title' => (string) $title, 'products' => []];
        $product = $this->normalizeProduct($product);

        $currentProductId = (int) ($product['id_product'] ?? 0);
        $sku = $this->extractSku((string) $skuField, $product);
        if ($sku === '') {
            return $empty;
        }

        $client = new SmartvitrinesApiClient($apiBaseUrl);
        $recommendedSkus = $client->getRecommendations((string) $publicKey, $sku, self::API_FETCH_LIMIT);
        if ($recommendedSkus === []) {
            return $empty;
        }

        $productIds = [];
        foreach ($recommendedSkus as $recommendedSku) {
            if (count($productIds) >= self::DISPLAY_LIMIT) {
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
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $product
     *
     * @return array<string, mixed>
     */
    private function normalizeProduct($product)
    {
        return [
            'id_product' => (int) ($product['id_product'] ?? $product['id'] ?? 0),
            'reference' => (string) ($product['reference'] ?? ''),
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
                return trim((string) ($product['reference'] ?? ''));
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
                return (int) Product::getIdByReference($sku);
        }
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
                WHERE p.upc = \'' . pSQL($upc) . '\'
                LIMIT 1';

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

        $rawProducts = [];
        foreach ($productIds as $productId) {
            $rawProducts[] = ['id_product' => $productId];
        }

        $assembled = $assembler->assembleProducts($rawProducts);
        $presented = [];
        foreach ($assembled as $rawProduct) {
            $presented[] = $presenter->present(
                $presentationSettings,
                $rawProduct,
                $this->context->language
            );
        }

        return $presented;
    }
}
