<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Resolve SKU/identificador de produto alinhado ao g:id do feed XML e ao Campo SKU do BO.
 *
 * reference: referência quando preenchida; senão id_product_attribute (contexto) → default → id_product.
 * id: sempre id_product_attribute (contexto) → default → id_product (ignora referência).
 */
final class SmartvitrinesProductSkuResolver
{
    /**
     * @param array<string, mixed> $rowOrProduct linha de carrinho/pedido ou produto normalizado do hook
     */
    public static function extractSku($skuField, array $rowOrProduct, $fallbackReferenceWhenEmpty = false)
    {
        switch ((string) $skuField) {
            case 'id':
                return self::extractProductId($rowOrProduct);
            case 'ean13':
                $sku = trim((string) ($rowOrProduct['product_ean13'] ?? $rowOrProduct['ean13'] ?? ''));
                if ($sku !== '') {
                    return $sku;
                }

                return $fallbackReferenceWhenEmpty ? self::extractReferenceSku($rowOrProduct) : '';
            case 'upc':
                $sku = trim((string) ($rowOrProduct['product_upc'] ?? $rowOrProduct['upc'] ?? ''));
                if ($sku !== '') {
                    return $sku;
                }

                return $fallbackReferenceWhenEmpty ? self::extractReferenceSku($rowOrProduct) : '';
            default:
                return self::extractReferenceSku($rowOrProduct);
        }
    }

    /**
     * @param array<string, mixed> $rowOrProduct
     * @param Product|null       $product        produto PrestaShop (opcional, p.ex. export de pedido)
     */
    public static function extractSkuFromOrderRow($skuField, array $rowOrProduct, Product $product = null)
    {
        if ($product instanceof Product) {
            $merged = $rowOrProduct;
            if (!isset($merged['reference']) && $product->reference !== '') {
                $merged['reference'] = (string) $product->reference;
            }
            if (!isset($merged['ean13']) && $product->ean13 !== '') {
                $merged['ean13'] = (string) $product->ean13;
            }
            if (!isset($merged['upc']) && $product->upc !== '') {
                $merged['upc'] = (string) $product->upc;
            }
            if (!isset($merged['id_product']) && (int) $product->id > 0) {
                $merged['id_product'] = (int) $product->id;
            }

            return self::extractSku($skuField, $merged, true);
        }

        return self::extractSku($skuField, $rowOrProduct, true);
    }

    public static function resolveProductId($skuField, $sku)
    {
        switch ((string) $skuField) {
            case 'id':
                return self::resolveProductIdFromIdentifier($sku);
            case 'ean13':
                return (int) Product::getIdByEan13($sku);
            case 'upc':
                return self::resolveProductIdByUpc($sku);
            default:
                return self::resolveProductIdByReferenceSku($sku);
        }
    }

    /**
     * @param array<string, mixed> $rowOrProduct
     */
    private static function extractReferenceSku(array $rowOrProduct)
    {
        $reference = trim((string) (
            $rowOrProduct['reference_to_display']
            ?? $rowOrProduct['reference']
            ?? $rowOrProduct['product_reference']
            ?? ''
        ));
        if ($reference !== '') {
            return $reference;
        }

        return self::extractProductId($rowOrProduct);
    }

    /**
     * @param array<string, mixed> $rowOrProduct
     */
    private static function extractProductId(array $rowOrProduct)
    {
        $attributeId = (int) ($rowOrProduct['product_attribute_id'] ?? $rowOrProduct['id_product_attribute'] ?? 0);
        if ($attributeId > 0) {
            return (string) $attributeId;
        }

        $idProduct = (int) ($rowOrProduct['id_product'] ?? $rowOrProduct['product_id'] ?? $rowOrProduct['id'] ?? 0);
        if ($idProduct <= 0) {
            return '';
        }

        $defaultAttr = (int) Product::getDefaultAttribute($idProduct);
        if ($defaultAttr > 0) {
            return (string) $defaultAttr;
        }

        return (string) $idProduct;
    }

    private static function resolveProductIdFromIdentifier($sku)
    {
        $sku = trim((string) $sku);
        if ($sku === '' || !ctype_digit($sku)) {
            return 0;
        }

        $numeric = (int) $sku;
        if ($numeric <= 0) {
            return 0;
        }

        $fromAttribute = self::resolveProductIdByAttributeId($numeric);
        if ($fromAttribute > 0) {
            return $fromAttribute;
        }

        $product = new Product($numeric, false);
        if (Validate::isLoadedObject($product)) {
            return $numeric;
        }

        return 0;
    }

    private static function resolveProductIdByReferenceSku($sku)
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return 0;
        }

        $id = (int) Product::getIdByReference($sku);
        if ($id > 0) {
            return $id;
        }

        $id = self::resolveProductIdByProductReference($sku);
        if ($id > 0) {
            return $id;
        }

        return self::resolveProductIdByCombinationReference($sku);
    }

    private static function resolveProductIdByProductReference($reference)
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

    private static function resolveProductIdByCombinationReference($reference)
    {
        $reference = (string) $reference;
        if ($reference === '') {
            return 0;
        }

        $id = self::resolveProductIdByAttributeId((int) $reference);
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

    private static function resolveProductIdByAttributeId($attributeId)
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

    private static function resolveProductIdByUpc($upc)
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
}
