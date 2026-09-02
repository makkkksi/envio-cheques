-- Migración: Agregar valor APROBADA_TOPE al ENUM decision en solicitudes_aprobacion
-- Fecha: 2026-09-01
-- Segura: solo extiende el ENUM, no elimina valores existentes.

ALTER TABLE solicitudes_aprobacion
MODIFY COLUMN decision ENUM('APROBADA', 'RECHAZADA', 'APROBADA_TOPE') NULL;
