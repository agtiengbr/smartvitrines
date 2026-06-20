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
            $product = new Product((int) $row['product_id']);
            if (!Validate::isLoadedObject($product)) {
                continue;
            }

            $sku = $this->resolveSku($product);
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

    private function resolveSku(Product $product)
    {
        switch ($this->skuField) {
            case 'ean13':
                return (string) ($product->ean13 ?: $product->reference);
            case 'upc':
                return (string) ($product->upc ?: $product->reference);
            default:
                return (string) $product->reference;
        }
    }
}
