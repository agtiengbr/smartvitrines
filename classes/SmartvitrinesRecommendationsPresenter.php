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

    public function __construct(
        private Context $context,
    ) {}

    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $product
     *
     * @return array{title: string, products: list<array<string, mixed>>}
     */
    public function present(
        string $publicKey,
        string $apiBaseUrl,
        string $skuField,
        array|\ArrayAccess $product,
        string $title,
    ): array {
        $empty = ['title' => $title, 'products' => []];
        $product = $this->normalizeProduct($product);

        $currentProductId = (int) ($product['id_product'] ?? 0);
        $sku = $this->extractSku($skuField, $product);
        if ($sku === '') {
            return $empty;
        }

        $client = new SmartvitrinesApiClient($apiBaseUrl);
        $recommendedSkus = $client->getRecommendations($publicKey, $sku, self::API_FETCH_LIMIT);
        if ($recommendedSkus === []) {
            return $empty;
        }

        $productIds = [];
        foreach ($recommendedSkus as $recommendedSku) {
            if (count($productIds) >= self::DISPLAY_LIMIT) {
                break;
            }

            $id = $this->resolveProductId($skuField, $recommendedSku);
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
            'title' => $title,
            'products' => $this->presentProducts(array_values($productIds)),
        ];
    }

    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $product
     *
     * @return array<string, mixed>
     */
    private function normalizeProduct(array|\ArrayAccess $product): array
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
    private function extractSku(string $skuField, array $product): string
    {
        return match ($skuField) {
            'ean13' => trim((string) ($product['ean13'] ?? '')),
            'upc' => trim((string) ($product['upc'] ?? '')),
            default => trim((string) ($product['reference'] ?? '')),
        };
    }

    private function resolveProductId(string $skuField, string $sku): int
    {
        return match ($skuField) {
            'ean13' => (int) Product::getIdByEan13($sku),
            'upc' => $this->resolveProductIdByUpc($sku),
            default => (int) Product::getIdByReference($sku),
        };
    }

    private function resolveProductIdByUpc(string $upc): int
    {
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

    private function isVisibleProduct(int $productId): bool
    {
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
    private function presentProducts(array $productIds): array
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
            $this->context->getTranslator(),
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
                $this->context->language,
            );
        }

        return $presented;
    }
}
