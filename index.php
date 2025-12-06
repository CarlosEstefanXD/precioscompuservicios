<?php
// CompuServicios · Etiquetas Automatizadas — index.php

/* ===== Utilidades ===== */
function nlocal($v){ if($v===null||$v==='')return null; return (float)str_replace([',',' '],['.',''],$v); }
function redondeo_ref(float $x, bool $usar_redondeo=false, string $direccion='up'): float {
  $f = floor($x);
  $frac = $x - $f;

  if ($frac < 0.5) return $f;
  if (abs($frac - 0.5) < 1e-4) {
    if ($usar_redondeo) {
      return $direccion === 'up' ? $f + 1 : $f;
    }
    // si no se seleccionó redondeo manual, deja en .5
    return $f + 0.5;
  }
  return $f + 1;
}
function fmt_ref(float $v): string {
  $v=round($v*2)/2; $s=number_format($v,1,'.','');
  return rtrim(rtrim($s,'0'),'.');
}
function generar_cod(float $precio_base_usd,int $porcentaje): string {
  $medio = rtrim(rtrim(number_format($precio_base_usd, 2, '.', ''), '0'), '.');
  if (strpos($medio, '.') !== false) {
    [$ent, $dec] = explode('.', $medio);
    $ent  = str_pad($ent, 3, '0', STR_PAD_LEFT);
    $medio = $ent . '.' . $dec;
  } else {
    $medio = str_pad($medio, 4, '0', STR_PAD_LEFT);
  }

  // último tramo siempre de 2 dígitos
  $por_str = str_pad((string)$porcentaje, 2, '0', STR_PAD_LEFT);

  return 'COD-' . $medio . '-' . $por_str;
}

/* ===== Rutas (compatibles con XAMPP) ===== */
$docRoot   = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
$publicDir = str_replace('\\', '/', dirname(__FILE__));
$publicUrl = rtrim(str_replace($docRoot, '', $publicDir), '/');   // ej: /compuservicios_etiquetas/public

/* ===== Entradas ===== */
$precio_base_usd = isset($_POST['precio_base_usd'])? nlocal($_POST['precio_base_usd']) : null;
$d2_pct          = isset($_POST['d2_pct'])         ? nlocal($_POST['d2_pct'])          : null;
$fiscal_pct      = isset($_POST['fiscal_pct'])     ? nlocal($_POST['fiscal_pct'])      : 0.16;
$tasa_bcv        = isset($_POST['tasa_bcv'])       ? nlocal($_POST['tasa_bcv'])        : null;
$aplica_fiscal   = isset($_POST['aplica_fiscal']);
$usar_redondeo   = isset($_POST['usar_redondeo']);
$redondeo_direccion = isset($_POST['redondeo_direccion']) ? $_POST['redondeo_direccion'] : 'up';
$cantidad        = isset($_POST['cantidad'])       ? max(1,(int)$_POST['cantidad'])    : 1;
$ajustar_montos_grandes = isset($_POST['ajustar_montos_grandes']);
$lotes_raw     = isset($_POST['lotes']) ? trim((string)$_POST['lotes']) : '';
$errores_lotes = [];

// El porcentaje COD ahora es el mismo que el porcentaje base (en % entero)
$porcentaje_cod  = (int)round($d2_pct * 100);

/* ===== Plantilla de etiqueta y layout ===== */
$ancho_cm=4.8; $alto_cm=2.88;         // tamaño etiqueta
$margin_mm=0; $gap_mm=2;              // márgenes y separación
$img_base = $publicUrl.'/assets/etiqueta_base.png';  // imagen fija

/* ===== Cálculo (soporta un precio o varios) ===== */
$labels   = [];
$resumen  = null;
$hay_datos = false;

/**
 * Función auxiliar: calcula todo para un solo precio
 * y devuelve [$resumen, $ref_texto, $cod].
 */
function calcular_lote(
  float $pago_usd,
  float $d2_pct,
  float $fiscal_pct,
  float $tasa_bcv,
  bool $aplica_fiscal,
  bool $usar_redondeo,
  string $redondeo_direccion,
  bool $ajustar_montos_grandes,
  int $porcentaje_cod
){
  $descuento_usd = $pago_usd * $d2_pct;
  $venta_usd     = $pago_usd + $descuento_usd;
  $venta_usd_f   = $venta_usd * (1 + $fiscal_pct);
  $base_ref_usd  = $aplica_fiscal ? $venta_usd_f : $venta_usd;
  $venta_bs      = $base_ref_usd * $tasa_bcv;

  // ajuste opcional montos >= 10 a .5
  if ($ajustar_montos_grandes && $base_ref_usd >= 10) {
    $ent  = floor($base_ref_usd);
    $frac = $base_ref_usd - $ent;

    if ($frac > 0 && $frac < 0.5) {
      $ref_val = $ent + 0.5;
    } else {
      $ref_val = redondeo_ref($base_ref_usd, $usar_redondeo, $redondeo_direccion);
    }
  } else {
    $ref_val = redondeo_ref($base_ref_usd, $usar_redondeo, $redondeo_direccion);
  }

  $ref_texto = fmt_ref($ref_val);
  $cod       = generar_cod($pago_usd, $porcentaje_cod);

  $resumen = [
    'pago_usd'      => $pago_usd,
    'd2_pct'        => $d2_pct,
    'descuento_usd' => $descuento_usd,
    'venta_usd'     => $venta_usd,
    'fiscal_pct'    => $fiscal_pct,
    'venta_usd_f'   => $venta_usd_f,
    'tasa_bcv'      => $tasa_bcv,
    'venta_bs'      => $venta_bs,
    'ref'           => $ref_texto,
    'cod'           => $cod,
    'aplica_fiscal' => $aplica_fiscal
  ];

  return [$resumen, $ref_texto, $cod];
}

/* --- MODO MULTI: si el usuario llenó la caja "lotes" --- */
if ($lotes_raw !== '') {
  $lotes = [];
  foreach (preg_split('/\r\n|\r|\n/', $lotes_raw) as $line) {
    $line = trim($line);
    if ($line === '') continue;

    // Solo acepta: 10 x 4  (números, coma o punto)
    if (preg_match('/^([\d.,]+)\s*x\s*(\d+)$/i', $line, $m)) {
      $precio = nlocal($m[1]);
      $cant   = max(1, (int)$m[2]);

      if ($precio !== null) {
        $lotes[] = ['precio' => $precio, 'cantidad' => $cant];
      } else {
        $errores_lotes[] = $line;
      }
    } else {
      // línea con letras o formato inválido
      $errores_lotes[] = $line;
    }
  }                          

  if (!empty($lotes) && $tasa_bcv !== null) {
    $hay_datos = true;

    foreach ($lotes as $idx => $item) {
      $pago_usd        = $item['precio'];
      $cantidad_lote   = $item['cantidad'];

      [$res_lote, $ref_texto, $cod] = calcular_lote(
        $pago_usd,
        $d2_pct,
        $fiscal_pct,
        $tasa_bcv,
        $aplica_fiscal,
        $usar_redondeo,
        $redondeo_direccion,
        $ajustar_montos_grandes,
        $porcentaje_cod
      );

      // Usamos el primer lote para llenar el resumen de la tarjeta
      if ($idx === 0) {
        $resumen = $res_lote;
      }

      for ($i = 0; $i < $cantidad_lote; $i++) {
        $labels[] = ['ref' => $ref_texto, 'cod' => $cod];
      }
    }
  }

/* --- MODO CLÁSICO: un solo precio + cantidad --- */
} else {
  $hay_datos = ($precio_base_usd !== null && $tasa_bcv !== null);
  if ($hay_datos) {
    $pago_usd      = $precio_base_usd;
    $cantidad_lote = $cantidad;

    [$resumen, $ref_texto, $cod] = calcular_lote(
      $pago_usd,
      $d2_pct,
      $fiscal_pct,
      $tasa_bcv,
      $aplica_fiscal,
      $usar_redondeo,
      $redondeo_direccion,
      $ajustar_montos_grandes,
      $porcentaje_cod
    );

    for ($i = 0; $i < $cantidad_lote; $i++) {
      $labels[] = ['ref' => $ref_texto, 'cod' => $cod];
    }
  }
}

/* ===== Paginación: 36 por hoja ===== */
$per_page = 36;
$pages = array_chunk($labels, $per_page);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"> 
<title>CompuServicios · Etiquetas Automatizadas</title>

<meta name="viewport" content="width=device-width, initial-scale=1">
<base href="<?= htmlspecialchars($publicUrl.'/', ENT_QUOTES, 'UTF-8') ?>">

<style>
:root{
  --brand-blue: #22488F;   /* azul CompuServicios */
  --brand-orange: #F48120; /* naranja CompuServicios */
  --brand-dark: #0C2C68;   /* azul oscuro para detalles */
  --text: #1A1A1A;
  --muted: #6b7280;

  --label-w:<?= $ancho_cm ?>cm;
  --label-h:<?= $alto_cm ?>cm;
  --gap:<?= $gap_mm ?>mm;
  --margin-page: 0mm;
}
@page{
  size: Letter;
  margin: 0; /* sin márgenes reales */
}
*{ box-sizing:border-box; }
body{
  font-family: Segoe UI, Roboto, Arial, sans-serif;
  margin:0; padding:24px;
  color:#0f172a; background:#fafafa;
}
.wrapper{ max-width:1100px; margin:0 auto; }
.card{
  border:1px solid #e5e7eb; border-radius:14px;
  padding:22px 28px; background:#fff;
  box-shadow:0 1px 3px #00000010;
}
h1{
  text-align:center;
  margin:0 0 16px;
  font-size:26px;
  color: var(--brand-blue);  /* título principal azul */
}
h1, .multi-title{
  color: var(--brand-blue);
  font-weight: 700;
}

input:focus, select:focus, textarea:focus{
  outline: none;
  border-color: var(--brand-blue);
  box-shadow: 0 0 0 2px #22488F30;
}

/* ===== Encabezado con logo ===== */
.header-title{
  text-align:center;
  margin-bottom: 20px;
}

.title-logo{
  width: 380px;     /* tamaño ideal para escritorio */
  max-width: 90%;   /* responsive */
  height:auto;
  display:block;
  margin: 0 auto 6px auto;
}

.title-sub{
  font-size: 22px;
  font-weight: 600;
  color: var(--brand-blue);   /* azul corporativo */
  margin-top: 4px;
}

/* ===== Formulario (fila 1) ===== */
.form-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:14px 18px;
  align-items:end;
}
.form-grid > div{
  display:flex;
  flex-direction:column;
  justify-content:flex-end;
}
.form-grid label{
  font-size:13px;
  color:#334155;
  margin-bottom:6px;
  font-weight:500;
}
.form-grid a{
  color: var(--brand-blue);
  text-decoration: underline;
  font-weight: 600;
}

/* Inputs generales (fila 1 y fila 2) */
.form-grid input[type="text"],
.form-grid input[type="number"],
.row2 input[type="text"],
.row2 input[type="number"],
.row2 select{
  width:100%;
  height:42px;
  padding:8px 10px;
  border:1px solid #cbd5e1;
  border-radius:8px;
  font-size:14px;
  text-align:center;
  background:#fff;
}

/* ===== Formulario (fila 2) ===== */
.row2{
  display:grid;
  grid-template-columns:repeat(4, 1fr); /* por defecto: 4 columnas */
  gap:14px 18px;
  align-items:end;
  margin-top:4px;
}

/* Cuando hay 5 elementos visibles (redondeo activo) */
.row2.row2-5cols{
  grid-template-columns:repeat(5, 1fr);
}

/* Contenedores de check uniformes */
.check{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  height:42px;
  border:1px solid #cbd5e1;
  border-radius:8px;
  background:#f8fafc;
  font-size:14px;
  font-weight:500;
  cursor:pointer;
}
.check input[type="checkbox"]{
  width:18px;
  height:18px;
  accent-color: var(--brand-orange);  /* antes #059669 */
  cursor:pointer;
}

/* Selector de dirección del redondeo */
#opciones_redondeo {
  display: flex;
  flex-direction: column;
  gap: 6px; /* separación igual que el label de los inputs */
}

#opciones_redondeo label {
  font-size: 13px;
  color: #334155;
  font-weight: 500;
  margin: 0;
}

#opciones_redondeo select {
  width: 100%;
  height: 42px;
  padding: 8px 10px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #fff;
  font-size: 14px;
  text-align: center;
}

/* Botones */
.actions{
  display:flex;
  justify-content:center;
  gap:12px;
  margin:18px 0;
}
button{
  height:40px;
  padding:0 20px;
  border:1px solid var(--brand-orange);
  background: var(--brand-orange);   /* ahora naranja */
  color:#fff;
  border-radius:10px;
  cursor:pointer;
  font-weight:600;
  transition: 0.2s;
}
button:hover{
  background: #d86f1c;               /* naranja un poco más oscuro */
  border-color: #d86f1c;
}
button.secondary{
  background:#fff;
  color:var(--brand-blue);           /* secundario azul */
  border-color: var(--brand-blue);
}
button.secondary:hover{
  background: var(--brand-blue);
  color:#fff;
}

/* ===== Resumen 2×5 centrado ===== */
.resume{
  display:grid;
  grid-template-columns: repeat(5, minmax(140px, 1fr));
  gap:10px 12px;
  max-width:900px;
  margin:12px auto 0;
}
.pill{  
  background:#f1f5f9;
  border:1px solid var(--brand-blue);
  color: var(--brand-dark);
  border-radius:10px;
  padding:10px;
  font-size:12px;
  text-align:center;
}

/* ===== Varias hojas ===== */
.sheets{
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:24px; /* solo pantalla */
}

/* Cada página (área visible para una hoja Carta con márgenes) */
.page{
  width:100%;
  margin:0;
  padding: 2mm 5mm;        /* 2mm arriba/abajo, 5mm a los lados */
  box-sizing: border-box;
  display:flex;
  justify-content:center;  /* centra la grilla dentro de ese margen */
  align-items:flex-start;
}

/* Hoja: 4 × 8 = 32 etiquetas */
.sheet{
  display:grid;
  grid-template-columns: repeat(4, var(--label-w));
  grid-auto-rows: var(--label-h);
  gap: 0; /* SIN espacios entre etiquetas */
  width: calc(4 * var(--label-w));
  height: calc(9 * var(--label-h)); /* o 8 si usas 32 por hoja */
  overflow: hidden;
}

/* Etiqueta */
.label{
  position: relative;
  width: var(--label-w);
  height: var(--label-h);
  margin: 0;
  padding: 0;
  border: none;                 /* ya tienes el borde azul dentro de la imagen */
  overflow: hidden;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}

/* La imagen debe pegarse a todos los bordes de la etiqueta */
.label .bg{
  position:absolute;
  inset:0;
  width:100%;
  height:100%;
  object-fit:contain;       /* NO recorta NUNCA la imagen */
  background:#fff;          /* rellena el espacio sobrante */
  z-index:0;
}

/* Textos sobre la imagen */
.label .ref,
.label .cod{
  position:absolute;
  z-index:1;
  color:#000;
}

/* REF: 775 centrado en una sola línea */
.label .ref{
  position:absolute;
  left:50%;
  top:55%;                   /* ajusta 60–65% según lo quieras un poco más arriba/abajo */
  transform:translate(-50%, -50%);
  font-weight:700;
  font-size:22pt;
  text-align:center;
  line-height:1;
  white-space:nowrap;        /* evita salto de línea aunque tenga más dígitos */
}

/* El número usa el mismo tamaño de letra que el texto "REF:" */
.label .ref .num{
  font-size:inherit;
  font-weight:inherit;
}

/* COD en la esquina inferior derecha, con margen del borde azul */
.label .cod{
  right:2%;
  bottom:7%;
  font-size:9pt;
}
.multi-block{
  margin-top:16px;
}
.multi-block label{
  display:block;
  font-size:13px;
  color:#334155;
  margin-bottom:6px;
  font-weight:500;
}
.multi-block textarea{
  width:100%;
  border:1px solid #cbd5e1;
  border-radius:8px;
  padding:8px 10px;
  font-size:14px;
  resize:vertical;
}
.multi-block small{
  display:block;
  margin-top:4px;
  font-size:12px;
  color:#64748b;
}

.multi-quick{
  margin-top:8px;
  display:flex;
  align-items:center;
  gap:8px;
}
.multi-quick input[type="number"]{
  width:120px;
  border:1px solid #cbd5e1;
  border-radius:8px;
  padding:6px 8px;
  font-size:14px;
}
.multi-quick span{
  font-size:16px;
}
.multi-quick button{
  height:34px;
  padding:0 14px;
  border-radius:8px;
  border:1px solid var(--brand-orange);
  background: var(--brand-orange);
  color:#fff;
  font-size:13px;
  cursor:pointer;
  transition: 0.2s;
}
.multi-quick button:hover{
  background:#d86f1c;
  border-color:#d86f1c;
}

.multi-error{
  margin-top:8px;
  padding:8px 10px;
  border-radius:8px;
  background:#fee2e2;
  color:#991b1b;
  font-size:12px;
}
.multi-error ul{
  margin:4px 0 0;
  padding-left:18px;
}

.section-divider{
  margin:20px 0 14px;
  border:0;
  border-top:1px solid #e2e8f0;
}

.multi-block{
  margin-top:0;
}

.multi-title{
  text-align:center;
  font-size:26px; /* igual al h1 */
  font-weight:700;
  margin:0 0 16px 0;
  color: var(--brand-orange);
}

/* ===== Responsive ===== */
@media(max-width:1000px){
  .resume{
    grid-template-columns: repeat(4, minmax(140px,1fr));
  }
}
@media(max-width:900px){
  .form-grid{
    grid-template-columns:repeat(2,1fr);
  }
  .row2{
    grid-template-columns:repeat(2,1fr);
  }
  .resume{
    grid-template-columns: repeat(3, minmax(140px,1fr));
  }
}
@media(max-width:600px){
  .form-grid{
    grid-template-columns:1fr;
  }
  .row2{
    grid-template-columns:1fr;
  }
  .resume{
    grid-template-columns: repeat(2, minmax(140px,1fr));
  }
}

/* ===== Print ===== */
@media print{
  @page{
    size: Letter;
    margin: 0;
  }

  body{
    padding:0;
    background:#fff;
  }

  .card,
  .actions,
  .resume{
    display:none !important;
  }

  .sheets{
    gap:0 !important;
  }

  .page{
    page-break-after: always;
    break-after: page;
    margin:0;
    padding: 0;                 /* sin padding extra */
    display:flex;
    justify-content:center;     /* centrado horizontal */
    align-items:center;         /* centrado vertical */
  }

  .page:last-child{
    page-break-after: auto;
    break-after: auto;
  }

  .sheet{
    transform: scale(0.97);     /* ajusta 0.95 / 0.97 según el margen que quieras */
    transform-origin: center center;
  }

  .label{
    border:none;
  }

  /* 👇 SOLO para quitar el espacio arriba/abajo entre etiquetas */
  .label .bg{
    transform: scaleY(1.10);      /* prueba 1.05; si aún ves línea blanca, sube a 1.06 */
    transform-origin: center;
  }

  .label .cod{
    bottom:2%;   /* antes 5% — ahora queda más pegado a la franja azul */
  }
}

</style>

</head>
<body>
<div class="wrapper">
  <div class="card">
    
  <div class="header-title">
    <img src="assets/logo_compuservicios.png" alt="CompuServicios" class="title-logo">
    <div class="title-sub">Etiquetas Automatizadas</div>
  </div>

    <form method="post">
      <!-- Fila 1 -->
      <div class="form-grid">
        <div>
          <label>Pago en $ (Precio base)</label>
          <input type="number" step="0.01" name="precio_base_usd"
                 value="<?= isset($_POST['precio_base_usd'])?htmlspecialchars($_POST['precio_base_usd']):'' ?>" required>
        </div>
        <div>
          <label>% Base</label>
          <input 
              type="text" 
              name="d2_pct" 
              placeholder="Ej: 0.45 para 45%" 
              value="<?= htmlspecialchars(isset($_POST['d2_pct']) ? $_POST['d2_pct'] : $d2_pct) ?>"
          >
        </div>
        <div>
          <label>% Fiscal</label>
          <input type="text" name="fiscal_pct" value="<?= htmlspecialchars(isset($_POST['fiscal_pct'])?$_POST['fiscal_pct']:$fiscal_pct) ?>">
        </div>
        <div>
        <label>
          <a href="https://www.bcv.org.ve/" target="_blank" class="bcv-link">Tasa BCV (Bs/USD)</a>
        </label>
          <input type="text" name="tasa_bcv" value="<?= isset($_POST['tasa_bcv']) ? htmlspecialchars($_POST['tasa_bcv']) : '' ?>" required>
        </div>
      </div>

      <!-- Fila 2 -->
      <div class="row2">
        <label class="check">
          <input type="checkbox" name="aplica_fiscal" <?= $aplica_fiscal ? 'checked' : '' ?>>
          <span>Aplicar % Fiscal</span>
        </label>

        <label class="check">
          <input type="checkbox" name="usar_redondeo" id="usar_redondeo" <?= $usar_redondeo ? 'checked' : '' ?>>
          <span>Redondear Precio (.5)</span>
        </label>

        <label class="check">
          <input type="checkbox" name="ajustar_montos_grandes" <?= $ajustar_montos_grandes ? 'checked' : '' ?>>
          <span>Ajustar montos ≥ 10 a .5</span>
        </label>

        <div id="opciones_redondeo" style="<?= $usar_redondeo ? '' : 'display:none;' ?>">
          <label>Dirección del Redondeo</label>
          <select name="redondeo_direccion">
            <option value="up" <?= $redondeo_direccion === 'up' ? 'selected' : '' ?>>Hacia Arriba</option>
            <option value="down" <?= $redondeo_direccion === 'down' ? 'selected' : '' ?>>Hacia Abajo</option>
          </select>
        </div>

        <div>
          <label>Cantidad</label>
          <input type="number" step="1" min="1" name="cantidad" value="<?= htmlspecialchars($cantidad) ?>" required>
        </div>
      </div>

      <hr class="section-divider">

      <div class="multi-block">
        <h2 class="multi-title">Precios en Lote</h2>

        <label for="lotes_textarea">Varios precios (opcional)</label>
        <textarea id="lotes_textarea" name="lotes" rows="3" placeholder="Ejemplo:
      10 x 4
      20 x 8
      35.5 x 3"><?= isset($_POST['lotes']) ? htmlspecialchars($_POST['lotes']) : '' ?></textarea>

        <small>
          Cada línea debe tener el formato <code>precio x cantidad</code>.  
          Si llenas esta sección se ignorarán los campos “Pago en $” y “Cantidad” de arriba.
        </small>

        <div class="multi-quick">
          <input type="number" step="0.01" min="0" id="quick_precio" placeholder="Precio">
          <span>×</span>
          <input type="number" step="1" min="1" id="quick_cantidad" placeholder="Cant.">
          <button type="button" id="btn_add_lote">Añadir</button>
        </div>

        <?php if (!empty($errores_lotes)): ?>
          <div class="multi-error">
            Se ignoraron estas líneas por tener un formato inválido:
            <ul>
              <?php foreach ($errores_lotes as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>

      <script>
        const checkRedondeo = document.getElementById('usar_redondeo');
        const opciones = document.getElementById('opciones_redondeo');
        const row2 = document.querySelector('.row2');

        function actualizarRedondeoUI() {
          const activo = checkRedondeo.checked;
          opciones.style.display = activo ? '' : 'none';

          if (activo) {
            row2.classList.add('row2-5cols');
          } else {
            row2.classList.remove('row2-5cols');
          }
        }

        checkRedondeo.addEventListener('change', actualizarRedondeoUI);
        actualizarRedondeoUI();


        // ==== Atajo para lotes: Precio + Cantidad -> textarea "lotes" ====
        const txtLotes       = document.querySelector('textarea[name="lotes"]');
        const quickPrecio    = document.getElementById('quick_precio');
        const quickCantidad  = document.getElementById('quick_cantidad');
        const btnAddLote     = document.getElementById('btn_add_lote');

        if (btnAddLote) {
          btnAddLote.addEventListener('click', () => {
            const precioVal = parseFloat(quickPrecio.value.replace(',', '.'));
            const cantVal   = parseInt(quickCantidad.value, 10);

            if (isNaN(precioVal) || precioVal <= 0 || isNaN(cantVal) || cantVal <= 0) {
              alert('Introduce un precio y una cantidad válidos.');
              return;
            }

            // Formateo sencillo del precio (máx 2 decimales)
            let precioStr = precioVal.toFixed(2);
            // quitar ceros sobrantes
            precioStr = precioStr.replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');

            const linea = `${precioStr} x ${cantVal}`;

            if (txtLotes.value.trim() === '') {
              txtLotes.value = linea;
            } else {
              txtLotes.value += '\n' + linea;
            }

            quickPrecio.value   = '';
            quickCantidad.value = '';
            quickPrecio.focus();
          });
        }
      </script>

      <div class="actions">
        <button type="submit">Calcular y Generar</button>
        <button type="button" class="secondary" onclick="window.print()">Imprimir</button>
      </div>
    </form>

    <?php if($resumen): ?>
    <div class="resume">
      <div class="pill"><b>Pago $:</b><br><?=number_format($resumen['pago_usd'],2,',','.')?></div>
      <div class="pill"><b>Porcentaje:</b><br><?=number_format($resumen['d2_pct']*100,2,',','.')?>%</div>
      <div class="pill"><b>Desc. $:</b><br><?=number_format($resumen['descuento_usd'],2,',','.')?></div>
      <div class="pill"><b>Venta $:</b><br><?=number_format($resumen['venta_usd'],2,',','.')?></div>
      <div class="pill"><b>Fiscal:</b><br><?=number_format($resumen['fiscal_pct']*100,2,',','.')?>%</div>
      <div class="pill"><b>Venta $ F:</b><br><?=number_format($resumen['venta_usd_f'],2,',','.')?></div>
      <div class="pill"><b>BCV:</b><br><?=number_format($resumen['tasa_bcv'],2,',','.')?></div>
      <div class="pill"><b>Venta Bs:</b><br><?=number_format($resumen['venta_bs'],2,',','.')?></div>
      <div class="pill"><b>REF:</b><br><?=$resumen['ref']?></div>
      <div class="pill"><b>COD:</b><br><?=$resumen['cod']?></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($hay_datos): ?>
    <div class="sheets">
      <?php foreach ($pages as $chunk): ?>
        <div class="page">
          <div class="sheet">
            <?php foreach ($chunk as $e): ?>
              <div class="label">
                <img class="bg" src="<?= htmlspecialchars($img_base, ENT_QUOTES, 'UTF-8') ?>" alt="">
                <div class="ref">REF: <span class="num"><?= htmlspecialchars($e['ref'], ENT_QUOTES, 'UTF-8') ?></span></div>
                <div class="cod"><?= htmlspecialchars($e['cod'], ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
</body>
</html>