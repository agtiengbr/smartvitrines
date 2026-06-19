# Módulo PrestaShop SmartVitrines

Compatível com **PrestaShop 1.7.x a 9**.

## Instalação

1. Copie a pasta `smartvitrines` para `modules/` da loja:

   ```bash
   cp -r prestashop/modules/smartvitrines /caminho/da/loja/modules/
   ```

2. No back-office: **Módulos → SmartVitrines → Configurar**

3. Preencha:
   - **Public Key** — `pk_live_...` (CLI `sv:tenant:create`)
   - **Secret Key** — mesma `sk_live_...` do tenant
   - **API URL** — vazio para produção; dev: `http://host.docker.internal:18080`
   - **Campo SKU** — alinhado ao tenant (`reference`, `ean13`, `upc`)

4. Cadastre no tenant SmartVitrines:
   - `platform_url` = URL base da loja (também usada para CORS no browser)
   - `platform_type` = `prestashop`

   ```bash
   docker compose run --rm cli php bin/console sv:tenant:create "Minha Loja" \
     --platform-url=https://loja.exemplo.com \
     --platform-type=prestashop
   ```

## Endpoint de pedido (worker)

```
GET {platform_url}/index.php?fc=module&module=smartvitrines&controller=order&id={order_ref}
Authorization: Bearer {secret_key}
```

Resposta JSON: `id_pedido`, `data`, `total`, `items[]`.

## Fluxo

1. SDK (hook `displayHeader`) registra views na API
2. Confirmação do pedido (`displayOrderConfirmation`) dispara conversão via SDK
3. Worker SmartVitrines puxa pedido no endpoint acima e incrementa a matriz
4. PDP (hook `displayFooterProduct`) exibe até **4** recomendações renderizadas no servidor (mesmo layout dos acessórios no tema Hummingbird)

## Recomendações na página do produto

Hook: `displayFooterProduct` — bloco **"Você também pode se interessar por:"** (traduzível via `$this->l()`).

- Consulta `GET /v1/recommendations` no PHP (não usa SDK no browser)
- Resolve SKUs → produtos PrestaShop (`reference` / `ean13` / `upc`)
- Apresenta com `ProductListingPresenter` (miniaturas iguais aos acessórios)

**API URL** no BO deve ser acessível **do container/servidor PHP da loja** (ex.: `http://host.docker.internal:18080` em Docker WSL, não `localhost` do host).

## Teste manual do endpoint

```bash
curl -s "https://loja.exemplo.com/index.php?fc=module&module=smartvitrines&controller=order&id=12345" \
  -H "Authorization: Bearer sk_live_..."
```
