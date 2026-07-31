---
name: backend_api_development
description: Use this skill whenever you need to create, modify, or debug PHP backend APIs, endpoints, or backend integrations.
---

# Desarrollo de API Backend

Estás trabajando en la capa Backend del proyecto.

## Carga Selectiva de Contexto
Antes de modificar o crear un endpoint, lee SOLAMENTE los documentos relevantes para el alcance de tu tarea:
1. **Contratos y parámetros:** Lee `docs/API.md`.
2. **Consultas SQL o Estructuras:** Si tu tarea afecta la base de datos, lee `docs/DATABASE.md`.
3. **Flujos de Estado:** Si el endpoint cambia el estado de un documento (Cobranza, Cheque), lee `docs/BUSINESS_RULES.md` para entender las transiciones permitidas.
4. **Subida de Archivos o Autenticación:** Lee `docs/SECURITY.md` para conocer el middleware y las validaciones de uploads.
5. **Ejemplos de Código:** Si necesitas ver cómo se implementa una transacción PDO o cómo responder en JSON, lee `docs/CODING_STANDARDS.md`.

## Directrices de Implementación
- Sigue siempre la estructura estándar (Headers -> require_config -> Validación Método -> getUsuarioActual() -> inputs -> try/catch).
- Las funciones del backend en PHP deben usar `camelCase`.
- Utiliza `error_log()` para registrar excepciones internas; nunca las devuelvas al cliente.
