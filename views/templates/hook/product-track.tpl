{**
 * Emite view explicitamente — temas PS 1.7 (ex.: IQIT) costumam não incluir JSON-LD Product.
 *}
{if $smartvitrines_track_sku}
<script>
(function () {
  var sku = '{$smartvitrines_track_sku|escape:'javascript'}';
  function emitView() {
    if (window.SmartVitrines && typeof SmartVitrines.trackView === 'function') {
      SmartVitrines.trackView(sku).catch(function () {});
      return;
    }
    window.setTimeout(emitView, 50);
  }
  emitView();
})();
</script>
{/if}
