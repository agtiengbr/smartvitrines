{**
 * SmartVitrines — recomendações na PDP (mesmo layout dos acessórios / module-products).
 *}
{extends file='components/module-products.tpl'}

{block name='module_products_variables'}
  {assign var="products" value=$smartvitrines_products}
  {assign var="need_container" value=false}
{/block}

{block name='module_products_name'}product__smartvitrines smartvitrines-recommendations{/block}

{block name='module_products_title'}
  {include file='components/section-title.tpl' title=$smartvitrines_title}
{/block}
