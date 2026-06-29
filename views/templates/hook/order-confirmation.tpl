{**
 * SmartVitrines — SDK + conversão na confirmação do pedido
 * PS 1.6 não injeta displayHeader nesta página; o SDK precisa estar inline.
 * Usa fila q[] do SDK: init antes de trackConversion (evita race com script async).
 *}
<script>
(function (w, d, s, u, orderRef, publicKey) {
  var config = { publicKey: publicKey };

  function enqueueAndLoad() {
    w.SmartVitrines = w.SmartVitrines || { q: [] };
    w.SmartVitrines.init = function (c) { w.SmartVitrines.q.push(['init', c]); };
    w.SmartVitrines.trackConversion = function (ref) {
      w.SmartVitrines.q.push(['trackConversion', ref]);
    };

    w.SmartVitrines.init(config);
    w.SmartVitrines.trackConversion(orderRef);

    var j = d.createElement(s);
    j.async = true;
    j.src = u;
    d.head.appendChild(j);
  }

  // SDK já carregado (ex.: tema injetou header antes do checkout)
  if (w.SmartVitrines && w.SmartVitrines.version && typeof w.SmartVitrines.init === 'function' && typeof w.SmartVitrines.trackConversion === 'function') {
    w.SmartVitrines.init(config).then(function () {
      return w.SmartVitrines.trackConversion(orderRef);
    }).catch(function () {});
    return;
  }

  enqueueAndLoad();
})(window, document, 'script', '{$smartvitrines_script_url|escape:'javascript'}', '{$smartvitrines_order_ref|escape:'javascript'}', '{$smartvitrines_public_key|escape:'javascript'}');
</script>
