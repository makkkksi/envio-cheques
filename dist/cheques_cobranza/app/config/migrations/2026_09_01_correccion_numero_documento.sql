-- Migración: 2026_09_01_correccion_numero_documento.sql
-- Agrega numero_documento_original para auditaría de corrección de N° boleta por Tesorería.
-- El campo numero_documento ya existe; al corregirlo, el original del vendedor se guarda aquí.

ALTER TABLE rendicion_documentos
    ADD COLUMN IF NOT EXISTS numero_documento_original VARCHAR(50) NULL
        COMMENT 'Número de documento digitado originalmente por el vendedor antes de corrección de Tesorería'
        AFTER numero_documento;
