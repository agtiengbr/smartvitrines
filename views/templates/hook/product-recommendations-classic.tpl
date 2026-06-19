{**
 * SmartVitrines — recomendações na PDP (layout Classic, igual aos acessórios).
 *}
<section class="product-accessories clearfix smartvitrines-recommendations">
  <p class="h5 text-uppercase">{$smartvitrines_title|escape:'htmlall':'UTF-8'}</p>
  <div class="products row">
    {foreach from=$smartvitrines_products item="product" key="position"}
      {include file='catalog/_partials/miniatures/product.tpl' product=$product position=$position productClasses="col-xs-12 col-sm-6 col-lg-4 col-xl-3"}
    {/foreach}
  </div>
</section>
