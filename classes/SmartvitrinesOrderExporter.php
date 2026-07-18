<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/SmartvitrinesProductSkuResolver.php';

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

            $sku = SmartvitrinesProductSkuResolver::extractSkuFromOrderRow($this->skuField, $row, $product);
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
        $orderCurrency = new Currency((int) $order->id_currency);
        $shopDefaultCurrency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $orderConversionRate = (float) ($order->conversion_rate ?? 0);

        return [
            'id_pedido' => (string) $order->id,
            'data' => (new DateTimeImmutable($date))->format(DATE_ATOM),
            // O pedido guarda a taxa moeda-da-loja → moeda-do-pedido; invertemos
            // para entregar ao backend a taxa moeda-do-pedido → moeda padrão da loja.
            'currency' => (string) $orderCurrency->iso_code,
            'shop_default_currency' => (string) $shopDefaultCurrency->iso_code,
            'conversion_rate_to_shop_default' => $orderConversionRate > 0 ? 1 / $orderConversionRate : null,
            'total' => (float) $order->total_paid_tax_incl,
            'items' => $items,
        ];
    }
}
