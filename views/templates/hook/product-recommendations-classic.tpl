{**
 * SmartVitrines — recomendações na PDP (layout Classic, igual ps_viewedproduct / acessórios).
 *}
<section class="product-accessories smartvitrines-recommendations block block-section">
  <h4 class="section-title"><span>{$smartvitrines_title|escape:'htmlall':'UTF-8'}</span></h4>
  <div class="block-content">
    <div class="products slick-products-carousel products-grid slick-default-carousel slick-arrows-{$iqitTheme.pl_crsl_style}">
      {foreach from=$smartvitrines_products item="product" key="position"}
        {include file='catalog/_partials/miniatures/product.tpl' product=$product position=$position carousel=true}
      {/foreach}
    </div>
  </div>
</section>
