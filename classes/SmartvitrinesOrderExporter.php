<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

final class SmartvitrinesOrderExporter
{
    /** @var string */
    private $skuField;

    public function __construct($skuField = 'reference')
    {
        $this->skuField = (string) $skuField;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function export($orderId)
    {
        $orderId = (int) $orderId;
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return null;
        }

        $items = [];
        foreach ($order->getProducts() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $product = new Product((int) ($row['product_id'] ?? $row['id_product'] ?? 0));
            if (!Validate::isLoadedObject($product)) {
                continue;
            }

            $sku = $this->resolveSku($row, $product);
            if ($sku === '') {
                continue;
            }

            $quantity = (int) ($row['product_quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $unitPrice = (float) ($row['unit_price_tax_excl'] ?? $row['product_price'] ?? 0);

            $items[] = [
                'sku' => $sku,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
            ];
        }

        if ($items === []) {
            return null;
        }

        $date = $order->date_add ?? date('Y-m-d H:i:s');

        return [
            'id_pedido' => (string) $order->id,
            'data' => (new DateTimeImmutable($date))->format(DATE_ATOM),
            'total' => (float) $order->total_paid_tax_incl,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveSku(array $row, Product $product)
    {
        switch ($this->skuField) {
            case 'ean13':
                $sku = trim((string) ($row['product_ean13'] ?? $product->ean13 ?? ''));
                if ($sku !== '') {
                    return $sku;
                }

                return $this->resolveReferenceSku($row, $product);
            case 'upc':
                $sku = trim((string) ($row['product_upc'] ?? $product->upc ?? ''));
                if ($sku !== '') {
                    return $sku;
                }

                return $this->resolveReferenceSku($row, $product);
            default:
                return $this->resolveReferenceSku($row, $product);
        }
    }

    /**
     * Lojas sem reference no produto pai usam id da combinação (mesmo padrão do feed aggoogleshopping).
     *
     * @param array<string, mixed> $row
     */
    private function resolveReferenceSku(array $row, Product $product)
    {
        $reference = trim((string) ($row['product_reference'] ?? $product->reference ?? ''));
        if ($reference !== '') {
            return $reference;
        }

        $attributeId = (int) ($row['product_attribute_id'] ?? 0);
        if ($attributeId > 0) {
            return (string) $attributeId;
        }

        $defaultAttr = (int) Product::getDefaultAttribute((int) $product->id);
        if ($defaultAttr > 0) {
            return (string) $defaultAttr;
        }

        return (string) $product->id;
    }
}
