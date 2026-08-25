---
name: "Suite de Gestión Financiera Automarco"
description: "Sistema operativo financiero denso, claro y trazable, extendido nativamente desde Cheques Cobranza."
colors:
  automarco-blue: "#1e3a8a"
  automarco-blue-hover: "#1e40af"
  automarco-blue-soft: "#eff6ff"
  focus-blue: "#3b82f6"
  ink: "#0f172a"
  ink-secondary: "#475569"
  ink-muted: "#64748b"
  surface: "#ffffff"
  canvas: "#f8fafc"
  border: "#e2e8f0"
  border-soft: "#f1f5f9"
  success: "#166534"
  success-soft: "#dcfce7"
  warning: "#92400e"
  warning-soft: "#fef3c7"
  danger: "#991b1b"
  danger-soft: "#fee2e2"
  info: "#1d4ed8"
  info-soft: "#dbeafe"
typography:
  headline:
    fontFamily: "Outfit, sans-serif"
    fontSize: "1.35rem"
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: "-0.015em"
  title:
    fontFamily: "Outfit, sans-serif"
    fontSize: "1rem"
    fontWeight: 700
    lineHeight: 1.2
  metric:
    fontFamily: "Outfit, sans-serif"
    fontSize: "1.55rem"
    fontWeight: 700
    lineHeight: 1
    letterSpacing: "-0.02em"
  body:
    fontFamily: "Plus Jakarta Sans, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.45
  label:
    fontFamily: "Plus Jakarta Sans, sans-serif"
    fontSize: "0.66rem"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "0.045em"
rounded:
  sm: "6px"
  control: "8px"
  md: "10px"
  panel: "12px"
  lg: "14px"
  pill: "999px"
spacing:
  xs: "6px"
  sm: "8px"
  compact: "10px"
  md: "12px"
  lg: "14px"
  xl: "16px"
  xxl: "18px"
  section: "22px"
components:
  button-primary:
    backgroundColor: "{colors.automarco-blue}"
    textColor: "{colors.surface}"
    typography: "{typography.label}"
    rounded: "{rounded.control}"
    padding: "8px 13px"
    height: "40px"
  button-primary-hover:
    backgroundColor: "{colors.automarco-blue-hover}"
    textColor: "{colors.surface}"
    rounded: "{rounded.control}"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink-secondary}"
    typography: "{typography.label}"
    rounded: "{rounded.control}"
    padding: "8px 13px"
    height: "40px"
  input-compact:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.control}"
    padding: "0 12px"
    height: "38px"
  status-info:
    backgroundColor: "{colors.info-soft}"
    textColor: "{colors.info}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "4px 7px"
  status-success:
    backgroundColor: "{colors.success-soft}"
    textColor: "{colors.success}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "4px 7px"
  status-warning:
    backgroundColor: "{colors.warning-soft}"
    textColor: "{colors.warning}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "4px 7px"
  status-danger:
    backgroundColor: "{colors.danger-soft}"
    textColor: "{colors.danger}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "4px 7px"
---

# Design System: Suite de Gestión Financiera Automarco

## Overview

**Creative North Star: "La Mesa de Control Automarco"**

La Suite Automarco se presenta como una estación de trabajo financiera continua: sobria, compacta y orientada a decidir con evidencia. La marca aparece en la precisión del azul corporativo, la jerarquía tipográfica y la consistencia de los estados, mientras las superficies blancas y los neutros fríos permiten revisar grandes volúmenes sin ruido ornamental.

Rendiciones es una extensión operativa nativa de Cheques Cobranza. La jerarquía se conserva de forma explícita: App Switcher global, navegación lateral de submódulos, controles de bandeja y patrón maestro–detalle. El operador filtra, selecciona y audita en un mismo contexto; la interfaz no convierte cada paso en una pantalla independiente.

**Key Characteristics:**

- Densidad financiera legible, con controles compactos y cifras tabulares.
- Azul Automarco reservado para identidad, selección, foco informativo y acción primaria.
- Estados semánticos siempre expresados con texto, color y una señal de forma.
- Superficies claras separadas principalmente por bordes finos y cambios tonales.
- Jerarquía persistente desde el shell global hasta el detalle de una rendición.
- Adaptación móvil funcional que mantiene tabla, filtros y detalle disponibles.

## Colors

La paleta combina azul corporativo profundo con neutros fríos de alto contraste y pares semánticos de texto/fondo para estados financieros.

### Primary

- **Azul Automarco:** identidad del shell, acción primaria, títulos seleccionados y marca de fila activa.
- **Azul Automarco de interacción:** hover de acciones primarias y navegación activa.
- **Niebla azul:** fondo de selección, iconos informativos y controles activos sin convertir grandes superficies en bloques saturados.
- **Azul de foco:** contornos visibles, borde de campo enfocado y señales informativas puntuales.

### Secondary

- **Verde de resolución:** estados aprobados, pagados o completos y acciones positivas cuando están implementadas.
- **Ámbar de atención:** exceso presupuestario, recepción física pendiente y alertas que requieren revisión.
- **Rojo de rechazo:** rechazo, error y acciones destructivas reversibles por estado.
- **Azul informativo:** rendiciones enviadas o en revisión y estados operativos no terminales.

### Neutral

- **Tinta financiera:** texto principal, títulos, importes y datos que gobiernan la decisión.
- **Pizarra operativa:** texto secundario de filas, controles y explicaciones breves.
- **Pizarra atenuada:** metadatos, ayudas, resúmenes y encabezados de tabla.
- **Superficie blanca:** paneles, barras, formularios, modales y sidebar.
- **Lienzo frío:** fondo de aplicación, cabeceras de tabla y hover neutro.
- **Borde estructural:** separación entre regiones y contenedores.
- **Borde suave:** divisores internos de filas y metadatos.

### Named Rules

**The Semantic Pair Rule.** Cada estado usa el par de texto oscuro y fondo claro previsto; el color nunca reemplaza el nombre del estado ni su indicador circular.

**The Blue Selection Rule.** El azul corporativo marca contexto activo y acciones primarias; no se usa como relleno decorativo extendido dentro de las bandejas.

## Typography

**Display Font:** Outfit (con respaldo sans-serif)
**Body Font:** Plus Jakarta Sans (con respaldo sans-serif)

**Character:** Outfit aporta autoridad compacta a títulos, métricas e importes; Plus Jakarta Sans sostiene la lectura repetitiva de etiquetas, controles, tablas y metadatos. La combinación es contemporánea sin abandonar el tono administrativo.

### Hierarchy

- **Headline:** Outfit en negrita y espaciado ligeramente cerrado para encabezados de Dashboard y Vendedores.
- **Title:** Outfit seminegrita o negrita para títulos de sección, inspector, modal y agrupaciones internas.
- **Metric:** Outfit en negrita, cifras tabulares y espaciado cerrado para KPI e importes destacados.
- **Body:** Plus Jakarta Sans regular o media para controles, filas, descripciones y mensajes operativos.
- **Label:** Plus Jakarta Sans en negrita, compacta y a menudo en mayúsculas para cabeceras de tabla, metadatos y estados.

### Named Rules

**The Numeric Authority Rule.** Los importes, contadores y KPI usan Outfit con cifras tabulares para que columnas y comparaciones permanezcan estables.

**The Operational Reading Rule.** Plus Jakarta Sans gobierna la lectura densa; Outfit no sustituye el cuerpo ni las etiquetas repetitivas.

## Layout

El shell global conserva el App Switcher en la parte superior. Rendiciones agrega una sidebar secundaria blanca de 184 px en escritorio y entrega el resto del ancho al contenido. Dentro de Bandeja, controles segmentados y filtros preceden una vista maestro–detalle de 54/46: tabla a la izquierda e inspector a la derecha. Ambas regiones mantienen scroll interno y cabeceras o pies relevantes fijos dentro de su contexto.

La densidad se construye con un ritmo corto de 6–18 px en controles, celdas y secciones; las áreas ejecutivas admiten márgenes de 20–22 px. Los controles principales se mantienen entre 38 y 40 px de alto, mientras los destinos de navegación móvil llegan al mínimo operativo de 44 px.

A 1180 px la sidebar baja a 164 px, el maestro–detalle pasa a 57/43 y los metadatos se reorganizan en dos columnas. A 900 px la sidebar se vuelve navegación horizontal sticky, maestro y detalle se apilan verticalmente y la tabla conserva un ancho interno de 820 px con desplazamiento horizontal. A 640 px los filtros usan una grilla de dos columnas, la búsqueda ocupa el ancho completo, KPI y formularios pasan a una columna y los modales distribuyen sus acciones en filas flexibles.

**The Context Ladder Rule.** App Switcher, submódulo, controles y maestro–detalle siempre se leen en ese orden; ningún panel local suplanta la navegación global.

**The Table Integrity Rule.** En móvil la tabla conserva su estructura y se desplaza dentro de su contenedor; no se transforma en tarjetas ni oculta columnas implementadas.

## Elevation & Depth

El sistema es plano por defecto. La profundidad se expresa con superficies blancas sobre lienzo frío, bordes de 1 px y selección tonal. Las sombras aparecen sólo cuando comunican elevación o respuesta: un relieve mínimo en pestañas activas, una sombra ascendente bajo el pie de acciones del inspector, realce suave en acciones primarias y sombra estructural en modales.

### Shadow Vocabulary

- **Pestaña activa:** sombra corta y contenida para separar la opción seleccionada del control segmentado.
- **Acción primaria:** halo azul bajo que refuerza la acción sin competir con los datos.
- **Pie del inspector:** sombra ascendente tenue que indica acciones fijas sobre contenido desplazable.
- **Modal:** sombra estructural amplia para separar el diálogo del velo oscuro.

### Named Rules

**The Flat Workbench Rule.** Las superficies operativas permanecen planas en reposo; las sombras se reservan para estado, fijación o superposición.

## Shapes

La forma es compacta y suavemente redondeada. Los controles pequeños usan radios de 6–8 px; grupos, metadatos y tarjetas operativas usan 10–12 px; modales y estados vacíos alcanzan 14 px. Las píldoras de estado y los contadores usan radio completo. Los bordes finos son parte funcional de la jerarquía y no un adorno.

Los iconos son lineales, de 14–19 px en controles y 25 px en estados vacíos; heredan el color del contexto y acompañan etiquetas visibles. Los puntos circulares de estado y los nodos del stepper aportan una segunda señal además del texto.

**The Compact Radius Rule.** Los radios expresan escala: control, contenedor y superposición. No se aplican cápsulas a botones rectangulares ni radios grandes a tablas.

## Components

### Buttons

- **Shape:** rectángulos compactos de 8 px de radio, 40 px de alto y padding horizontal breve.
- **Primary:** fondo Azul Automarco, texto blanco y sombra azul baja; el hover usa el azul de interacción y eleva 1 px.
- **Secondary:** superficie blanca, borde estructural y texto de pizarra; el hover cambia a lienzo frío.
- **Semantic:** éxito puede usar relleno verde; advertencia y peligro usan fondo semántico claro con borde y texto oscuros.
- **Focus:** contorno azul visible de 3 px con separación exterior; el estado deshabilitado reduce opacidad y elimina la elevación.

### Chips

- **Style:** cápsulas compactas con texto en mayúsculas, punto circular y par semántico de primer plano/fondo.
- **State:** información, éxito, advertencia y peligro conservan etiquetas textuales; los contadores de pestaña son cápsulas numéricas independientes.

### Cards / Containers

- **Corner Style:** 10 px para documentos y metadatos; 12 px para KPI y paneles analíticos; 14 px para modales.
- **Background:** blanco sobre lienzo frío, con fondos semánticos claros sólo en alertas y estados.
- **Shadow Strategy:** planos en reposo; consultar Elevation & Depth para pie fijo y superposiciones.
- **Border:** borde estructural de 1 px y divisores suaves internos.
- **Internal Padding:** compacto, normalmente 10–18 px según jerarquía.

### Inputs / Fields

- **Style:** superficie blanca, borde fino, radio de 8 px y altura de 38–40 px; búsqueda con icono lineal a la izquierda.
- **Focus:** borde azul de foco y halo exterior translúcido de 3 px.
- **Error / Disabled:** los mensajes usan semántica textual; los controles deshabilitados conservan su forma y reducen énfasis sin desaparecer.

### Navigation

El App Switcher global establece el módulo activo. La sidebar de Rendiciones muestra Bandeja, Dashboard y, según permisos, Vendedores; su activo usa Niebla azul, borde azul claro, icono y texto reforzados. En tablet y móvil se convierte en una franja horizontal sticky con objetivos de 44 px y conserva el mismo orden. La URL hash refleja el submódulo (`#bandeja`, `#dashboard`, `#vendedores`) y permite entrada directa sin abandonar la página.

### Master–Detail Review

La tabla maestra usa encabezado sticky, texto compacto y cifras tabulares. Hover aplica un neutro leve; selección aplica Niebla azul y una línea interior Azul Automarco. La fila es navegable por teclado y muestra foco visible. El inspector conserva título y contexto en su barra superior, contenido con scroll propio y acciones válidas en un pie fijo. La alerta de exceso, evidencia documental, datos SII, trazabilidad y stepper aparecen dentro del detalle sólo cuando los datos implementados los permiten.

### Segmented Filters

Las pestañas de estado viven en un contenedor de lienzo frío con borde y 10 px de radio. La opción activa se eleva mínimamente sobre blanco y usa Azul Automarco; cada opción mantiene contador, icono y etiqueta. El estado de exceso conserva ámbar incluso cuando no está seleccionado.

### Modal

Los diálogos usan velo oscuro, tarjeta blanca de 14 px y cabecera sticky. La primera acción interactiva recibe foco al abrir; Escape y el botón de cierre restituyen el foco anterior. Los formularios mantienen la misma densidad y el mismo tratamiento de foco de la superficie principal.

## Do's and Don'ts

### Do:

- **Do** preservar la secuencia App Switcher → sidebar de submódulos → controles → maestro–detalle.
- **Do** usar Outfit para títulos, importes y KPI, y Plus Jakarta Sans para la operación densa.
- **Do** mantener texto, color y forma juntos en estados, alertas y pasos del flujo.
- **Do** conservar foco visible, navegación por teclado y destinos táctiles mínimos de 44 px donde el layout móvil lo exige.
- **Do** mantener la selección de la fila mientras el inspector revela evidencia y trazabilidad.
- **Do** conservar la navegación por hash de los submódulos implementados.

### Don't:

- **Don't** convertir Rendiciones en una aplicación visualmente separada de Cheques Cobranza.
- **Don't** reemplazar la tabla móvil por tarjetas ni eliminar su desplazamiento horizontal interno.
- **Don't** usar sombras o azul corporativo como decoración de grandes áreas sin función operativa.
- **Don't** comunicar éxito, exceso, rechazo o revisión sólo mediante color.
- **Don't** inventar estados, métricas, datos de vendedor o acciones que las APIs actuales no entregan.
- **Don't** romper el contexto maestro–detalle con navegación a páginas independientes para tareas ya resueltas en el inspector o los modales.
