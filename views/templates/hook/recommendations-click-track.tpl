{**
 * SmartVitrines — tracking de cliques na vitrine recomendada (delegação no container).
 *}
<script type="text/javascript">
(function () {
  var skuMap = {$smartvitrines_sku_map|@json_encode nofilter};
  var roots = document.querySelectorAll('.smartvitrines-recommendations');

  function resolveProductId(item) {
    if (!item) {
      return null;
    }

    var direct = item.getAttribute('data-id-product');
    if (direct) {
      return direct;
    }

    var nested = item.querySelector('[data-id-product]');
    return nested ? nested.getAttribute('data-id-product') : null;
  }

  function resolveSku(item) {
    if (!item) {
      return null;
    }

    var directSku = item.getAttribute('data-sv-sku');
    if (directSku) {
      return directSku;
    }

    var productId = resolveProductId(item);
    if (productId && skuMap && skuMap[productId]) {
      return skuMap[productId];
    }

    return null;
  }

  function bindClickTracking(root) {
    if (!root || root.getAttribute('data-sv-click-bound') === '1') {
      return;
    }

    root.setAttribute('data-sv-click-bound', '1');
    root.addEventListener('click', function (event) {
      var link = event.target.closest('a[href]');
      if (!link) {
        return;
      }

      var item = link.closest('article, li.ajax_block_product, [data-sv-sku]');
      if (!item || !root.contains(item)) {
        return;
      }

      // Add-to-cart da vitrine é coberto pelo hook PHP (Cart::updateQty).
      if (link.classList.contains('ajax_add_to_cart_button')) {
        return;
      }

      var sku = resolveSku(item);
      if (!sku || !window.SmartVitrines || typeof SmartVitrines.trackClick !== 'function') {
        return;
      }

      SmartVitrines.trackClick(sku).catch(function () {
        /* fire-and-forget */
      });
    });
  }

  for (var i = 0; i < roots.length; i += 1) {
    bindClickTracking(roots[i]);
  }
})();
</script>
