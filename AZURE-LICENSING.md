# Azure licensing blueprint

Este ficheiro acompanha a fase 2.2.0 e documenta a camada remota de licenciamento Azure para o plugin.

## Recursos Azure
- Azure Functions, endpoints HTTP
- Azure SQL Database
- Azure Key Vault
- Application Insights

## Endpoints esperados
- POST /license/generate
- POST /license/activate
- POST /license/validate
- POST /license/deactivate

## Headers esperados
- X-RoutesPro-Time
- X-RoutesPro-Signature
- X-RoutesPro-Product

## Payload base
Todos os requests incluem product_id, domain, site_url, fingerprint, plugin_version, wp_version e php_version.

## Resposta esperada
```json
{
  "success": true,
  "message": "license activated",
  "license": {
    "license_id": "lic_123",
    "activation_id": "act_123",
    "status": "active",
    "plan": "pro",
    "customer": "CLIENTEA",
    "customer_email": "cliente@example.com",
    "domain": "example.com",
    "fingerprint": "ABC123DEF456",
    "max_activations": 3,
    "activated_at": "2026-04-05T12:00:00Z",
    "expires_at": "2027-04-05T00:00:00Z",
    "features": {
      "ai_tools": true,
      "client_portal": true,
      "premium_branding": true
    }
  }
}
```
