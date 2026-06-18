{**
 * SmartVitrines — injeta SDK na loja
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
