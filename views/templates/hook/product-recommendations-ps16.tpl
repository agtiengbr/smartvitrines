{**
 * SmartVitrines — recomendações na PDP/carrinho (layout default-bootstrap PS 1.6).
 * Use smartvitrines-block (not accessories-block): product.js removes empty .accessories-block parents.
 *}
<section class="page-product-box smartvitrines-recommendations">
  <h3 class="page-product-heading">{$smartvitrines_title|escape:'htmlall':'UTF-8'}</h3>
  <div class="block products_block smartvitrines-block clearfix">
    <div class="block_content">
      <ul id="smartvitrines-bxslider" class="bxslider clearfix">
        {foreach from=$smartvitrines_products item=product name=smartvitrines_list}
          {if $product.available_for_order && !isset($restricted_country_mode)}
            {assign var='svProductLink' value=$product.link|default:$link->getProductLink($product.id_product, $product.link_rewrite, $product.category)}
            <li class="item product-box ajax_block_product{if $smarty.foreach.smartvitrines_list.first} first_item{elseif $smarty.foreach.smartvitrines_list.last} last_item{else} item{/if} product_accessories_description">
              <div class="product_desc">
                <a href="{$svProductLink|escape:'html':'UTF-8'}" title="{$product.legend|escape:'html':'UTF-8'}" class="product-image product_image">
                  <img class="lazyOwl" src="{$link->getImageLink($product.link_rewrite, $product.id_image, 'home_default')|escape:'html':'UTF-8'}" alt="{$product.legend|escape:'html':'UTF-8'}" width="{$homeSize.width|default:125}" height="{$homeSize.height|default:125}"/>
                </a>
                <div class="block_description">
                  <a href="{$svProductLink|escape:'html':'UTF-8'}" title="{l s='More' mod='smartvitrines'}" class="product_description">
                    {$product.description_short|strip_tags|truncate:25:'...'}
                  </a>
                </div>
              </div>
              <div class="s_title_block">
                <h5 itemprop="name" class="product-name">
                  <a href="{$svProductLink|escape:'html':'UTF-8'}">
                    {$product.name|truncate:20:'...':true|escape:'html':'UTF-8'}
                  </a>
                </h5>
                {if $product.show_price && !isset($restricted_country_mode) && !$PS_CATALOG_MODE}
                  <span class="price">
                    {if $priceDisplay != 1}
                      {displayWtPrice p=$product.price}
                    {else}
                      {displayWtPrice p=$product.price_tax_exc}
                    {/if}
                  </span>
                {/if}
              </div>
              <div class="clearfix" style="margin-top:5px">
                {if !$PS_CATALOG_MODE && ($product.allow_oosp || $product.quantity > 0) && isset($add_prod_display) && $add_prod_display == 1}
                  <div class="no-print">
                    <a class="exclusive button ajax_add_to_cart_button" href="{$link->getPageLink('cart', true, NULL, "qty=1&amp;id_product={$product.id_product|intval}&amp;token={$static_token}&amp;add")|escape:'html':'UTF-8'}" data-id-product="{$product.id_product|intval}" title="{l s='Add to cart' mod='smartvitrines'}">
                      <span>{l s='Add to cart' mod='smartvitrines'}</span>
                    </a>
                  </div>
                {/if}
              </div>
            </li>
          {/if}
        {/foreach}
      </ul>
    </div>
  </div>
</section>
{if $smartvitrines_products|@count > 0}
<script type="text/javascript">
$(document).ready(function () {
  if ($('#smartvitrines-bxslider li').length && !!$.prototype.bxSlider) {
    $('#smartvitrines-bxslider').bxSlider({
      minSlides: 1,
      maxSlides: 6,
      slideWidth: 178,
      slideMargin: 20,
      pager: false,
      nextText: '',
      prevText: '',
      moveSlides: 1,
      infiniteLoop: false,
      hideControlOnEnd: true
    });
  }
});
</script>
{/if}
