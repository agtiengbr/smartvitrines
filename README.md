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
2. Checkout (`actionValidateOrder`) envia `session_id` + `order_ref` → `POST /v1/events/conversion`
3. Worker SmartVitrines puxa pedido no endpoint acima e incrementa a matriz

## Teste manual do endpoint

```bash
curl -s "https://loja.exemplo.com/index.php?fc=module&module=smartvitrines&controller=order&id=12345" \
  -H "Authorization: Bearer sk_live_..."
```
