{**
 * SmartVitrines — conversão via SDK na confirmação do pedido
 *}
<script>
(function (orderRef) {
  var attempts = 0;

  function trackConversion() {
    if (window.SmartVitrines && typeof SmartVitrines.trackConversion === 'function') {
      SmartVitrines.trackConversion(orderRef).catch(function () {});
      return true;
    }

    return false;
  }

  (function waitForSdk() {
    if (trackConversion()) {
      return;
    }

    attempts += 1;
    if (attempts < 100) {
      window.setTimeout(waitForSdk, 100);
    }
  })();
})('{$smartvitrines_order_ref|escape:'javascript'}');
</script>
