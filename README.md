# Módulo PrestaShop SmartVitrines

Compatível com **PrestaShop 1.6.x a 9** e **PHP 7.1+** (recomendado PHP 7.4+ em PS 1.7+).

## Instalação

1. Copie a pasta `smartvitrines` para `modules/` da loja:

   ```bash
   cp -r prestashop/modules/smartvitrines /caminho/da/loja/modules/
   ```

2. No back-office: **Módulos → SmartVitrines → Configurar**

3. Preencha:
   - **Public Key** — `pk_live_...` (CLI `sv:tenant:create`)
   - **Secret Key** — mesma `sk_live_...` do tenant
   - **API URL** — vazio para produção; dev: URL acessível do container PHP (ex.: `http://host.docker.internal:18080`)
   - **Identificador do Produto** — alinhado ao tenant e ao `g:id` do feed (`reference`, `id`, `ean13`, `upc`)
   - **Layout das recomendações** — `Hummingbird` ou `Classic` (switch no BO)
   - **Limite na PDP** — quantidade na página do produto (default **4**)
   - **Limite no carrinho** — quantidade no carrinho (default **4**; ex.: **8** para vitrine maior)

4. Cadastre no tenant SmartVitrines:
   - `platform_url` = URL base da loja (também usada para CORS no browser)
   - `platform_type` = `prestashop`

   ```bash
   docker compose run --rm cli php bin/console sv:tenant:create "Minha Loja" \
     --platform-url=https://loja.exemplo.com \
     --platform-type=prestashop
   ```

## View automático (SDK)

O SDK tenta detectar o SKU via **JSON-LD** (`Product` em `application/ld+json`). Temas PrestaShop 1.7 customizados (ex.: IQIT / clubedovapor) frequentemente **não incluem** `product-jsonld.tpl` — só `WebPage` + microdata HTML.

Nesses casos o módulo emite **`SmartVitrines.trackView(sku)`** no hook `displayFooterProduct`, usando a referência do produto/combinação do PrestaShop.

Se a loja já tiver JSON-LD `Product` com `sku`, o SDK e o módulo deduplicam por página/sessão (apenas um evento).

```
GET {platform_url}/index.php?fc=module&module=smartvitrines&controller=order&id={order_ref}
Authorization: Bearer {secret_key}
```

Resposta JSON: `id_pedido`, `data`, `total`, `items[]`.

## Fluxo

1. SDK (hook `displayHeader`) registra views na API
2. Add-to-cart (hooks PHP de quantidade no carrinho) dispara `POST /v1/events/add-to-cart` com o SKU do produto e o `sv_session_id` do cookie do SDK
3. Confirmação do pedido (`displayOrderConfirmation`) dispara conversão via SDK
4. Worker SmartVitrines puxa pedido no endpoint acima e incrementa a matriz
5. PDP (hook `displayFooterProduct`) exibe recomendações renderizadas no servidor (quantidade configurável no BO, default **4**)
6. Carrinho (hook `displayShoppingCart`) exibe recomendações com base nos SKUs dos itens do carrinho (quantidade configurável no BO)

## Add-to-cart (server-side)

Cobre PDP, listagens, quickview, ajax e não-ajax — qualquer fluxo que chame `Cart::updateQty` com `operator = up`.

| Versão | Hook |
|---|---|
| PS 1.6 | `actionBeforeCartUpdateQty` |
| PS ≥ 1.7 | `actionCartUpdateQuantityBefore` |

- Resolve o SKU com o **Identificador do Produto** do BO (`reference` / `id` / `ean13` / `upc`), inclusive combinação
- Exige cookie `sv_session_id` do SDK (sem cookie → evento ignorado)
- API URL no BO precisa ser acessível **do PHP da loja** (mesmo requisito das recomendações)

Instalações já ativas: ao subir o ZIP **1.6.0+**, o PrestaShop roda `upgrade/upgrade-1.6.0.php` (atualiza o módulo no BO — **não** reinstalar). Alternativa CLI: `scripts/register_hooks.php`.

## Recomendações na página do produto

Hook: `displayFooterProduct` — bloco **"Você também pode se interessar por:"** (traduzível via `$this->l()`).

**Layout (configurável no BO):**

| Opção | Tema | Markup |
|---|---|---|
| Hummingbird | `hummingbird` | `components/module-products.tpl` (igual acessórios HB) |
| Classic | `classic` | `product-accessories` + grid `row` (igual acessórios Classic) |

- Consulta `GET /v1/recommendations?limit=N` no PHP (parâmetro **obrigatório**; teto na API via `SV_RECOMMENDATIONS_MAX_LIMIT`, default 20)
- Resolve SKUs → produtos PrestaShop (`reference` / `id` / `ean13` / `upc`)
- Apresenta com `ProductListingPresenter` (miniaturas iguais aos acessórios)

**API URL** no BO deve ser acessível **do container/servidor PHP da loja** (ex.: `http://host.docker.internal:18080` em Docker WSL, não `localhost` do host).

## Recomendações no carrinho

Hook: `displayShoppingCart` — bloco **"Complete sua compra com:"** abaixo da grade do carrinho (tema clubedovapor expõe o hook em `checkout/cart.tpl`).

- Coleta SKUs de todas as linhas do carrinho via `Cart::getProducts()` e o **Identificador do Produto** configurado (`reference`, `id`, `ean13`, `upc`)
- Chama `GET /v1/recommendations?sku=SKU1,SKU2,...&limit=N` (CSV + limit obrigatório)
- A API exclui grupos de origem; o módulo também omite produtos já presentes no carrinho
- Reutiliza os mesmos templates Smarty da PDP (`product-recommendations-hummingbird.tpl` / `classic`)

Instalações já ativas: ao subir o ZIP **1.6.0+**, o PrestaShop roda `upgrade/upgrade-1.6.0.php` (atualiza o módulo no BO — **não** reinstalar). Alternativa CLI: `scripts/register_hooks.php`.

## Teste manual do endpoint

```bash
curl -s "https://loja.exemplo.com/index.php?fc=module&module=smartvitrines&controller=order&id=12345" \
  -H "Authorization: Bearer sk_live_..."
```

## Bootstrap da matriz (cold-start)

Para lojas novas sem histórico de sessões na API, varra os `id_order` pelo **endpoint do módulo** (já usado em conversões) e reconstrua a matriz de afinidade:

```bash
# 1. Criar tenant (ex.: Evolução Pet)
docker compose run --rm cli php bin/console sv:tenant:create "Evolucao Pet" \
  --platform-url=https://www.evolucaopet.com.br

# 2. Configurar decay e feed XML
docker compose run --rm cli php bin/console sv:tenant:configure <id> \
  --decay-rate=0.99 \
  --xml-feed-url=https://www.evolucaopet.com.br/feed.xml

# 3. Sincronizar catálogo (preços para scoring)
docker compose run --rm cli php bin/console sv:catalog:sync --tenant-id=<id>

# 4. Bootstrap — ajuste TO ao último id_order da loja
docker compose run --rm cli php bin/console sv:matrix:bootstrap \
  --tenant-id=<id> \
  --from-order-id=1 \
  --to-order-id=50000 \
  --mark-processed
```

O comando consulta `GET …/module/smartvitrines/order?id=N` para cada ID (404 = pula), ordena pelo campo `data` da resposta e simula decay cronológico. **Nenhuma alteração no módulo** é necessária.

Opções úteis: `--no-until-negligible`, `--dry-run`, `--batch-size=100`.


## Changelog

### 1.6.0

- Add-to-cart global via hooks PHP (`actionBeforeCartUpdateQty` / `actionCartUpdateQuantityBefore`) → `POST /v1/events/add-to-cart`
- Remove tracking JS de ATC na vitrine (duplicado; o botão ajax também passa por `updateQty`)
- Script `upgrade/upgrade-1.6.0.php` registra o hook ao atualizar pelo ZIP (sem reinstalar)

### 1.5.1

- Exporta `currency`, `shop_default_currency` e `conversion_rate_to_shop_default` no endpoint de pedido (normalização multi-moeda na API)

### 1.5.0

- Campo **Identificador do Produto** renomeado no BO; opção **ID** para feeds GMCP (`g:id`)
- Correção: SKU unificado entre pedido, PDP, carrinho e recomendações (PS 1.7+)
- Correção: combinação sem referência usa `id_product_attribute` do contexto/linha
- Correção: resolver numérico ao exibir produtos recomendados (PS 1.7+)

### 1.4.2

Versão anterior.
