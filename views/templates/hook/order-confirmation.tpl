{**
 * SmartVitrines — SDK + conversão na confirmação do pedido
 * PS 1.6 não injeta displayHeader nesta página; o SDK precisa estar inline.
 *}
<script>
(function (w, d, s, u) {
  w.SmartVitrines = w.SmartVitrines || { q: [] };
  w.SmartVitrines.init = function (c) { w.SmartVitrines.q.push(['init', c]); };
  var j = d.createElement(s);
  j.async = true;
  j.src = u;
  d.head.appendChild(j);
})(window, document, 'script', '{$smartvitrines_script_url|escape:'javascript'}');

SmartVitrines.init({
  publicKey: '{$smartvitrines_public_key|escape:'javascript'}'
});
</script>
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
