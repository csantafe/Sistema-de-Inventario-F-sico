<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ========================================================================
// SHORTCODE 1: VERIFICADOR [elaborar_inventario]
// ========================================================================
add_shortcode( 'elaborar_inventario', 'invfacil_shortcode_elaborar' );

function invfacil_shortcode_elaborar() {
    if ( ! is_user_logged_in() ) return '<div style="color: #d63638; text-align:center; padding: 20px;">Inicie sesión.</div>';
    $usuario_actual = wp_get_current_user();
    if ( ! in_array( 'invfacil_verificador', (array) $usuario_actual->roles ) ) return '<div style="text-align:center; padding: 20px;">Solo Verificadores.</div>';

    global $wpdb;
    $tabla_conteos  = $wpdb->prefix . 'invfacil_conteos';
    $tabla_items    = $wpdb->prefix . 'invfacil_conteo_items';
    $tabla_puntos   = $wpdb->prefix . 'invfacil_puntos';
    $tabla_prod_erp = $wpdb->prefix . 'invfacil_productos_erp';
    $mensaje        = '';

    static $verificador_procesado = false;

    $conteo_pendiente = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabla_conteos WHERE verificador_id = %d AND estado = 'pendiente' LIMIT 1", $usuario_actual->ID));

    if ( !empty($_POST['maleta_json']) ) {
        $datos_desempaquetados = json_decode(stripslashes($_POST['maleta_json']), true);
        if (is_array($datos_desempaquetados)) {
            $_POST['items'] = $datos_desempaquetados;
        }
    }

    if ( isset($_POST['invfacil_enviar_firma']) && isset($_POST['conteo_id']) && !$verificador_procesado ) {
        $verificador_procesado = true; 
        $conteo_id = intval($_POST['conteo_id']);
        
        $conteo = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabla_conteos WHERE id = %d", $conteo_id));
        if ( ! $conteo ) {
            return '<div style="background-color: #f8d7da; color: #721c24; padding: 20px; text-align: center; border-radius: 8px; margin: 20px;"><strong>Error:</strong> La asignación ya fue procesada o eliminada.</div>';
        }

        if ( isset($_POST['items']) ) {
            foreach($_POST['items'] as $item_id => $datos) {
                $cant_raw = preg_replace('/[^\d.,-]/', '', sanitize_text_field($datos['cantidad']));
                $cant_limpia = str_replace(',', '.', $cant_raw);
                $cant_sql = number_format(floatval($cant_limpia), 2, '.', '');
                
                $unidad_conteo  = sanitize_text_field($datos['unidad_conteo']);
                $fecha_v = !empty($datos['fecha_vencimiento']) ? sanitize_text_field($datos['fecha_vencimiento']) : null;

                $wpdb->update($tabla_items, array(
                    'codigo'             => isset($datos['codigo']) ? sanitize_text_field($datos['codigo']) : '',
                    'unidad_conteo'      => $unidad_conteo,
                    'cantidad'           => $cant_sql,
                    'fecha_vencimiento'  => $fecha_v,
                    'observaciones'      => isset($datos['observaciones']) ? sanitize_textarea_field($datos['observaciones']) : ''
                ), array('id' => intval($item_id)));
            }
        }

        if ( !empty($_POST['nuevos_items']) && is_array($_POST['nuevos_items']) ) {
            foreach($_POST['nuevos_items'] as $nuevo) {
                $info_prod = explode(' - ', sanitize_text_field($nuevo['nombre_producto']), 2);
                $codigo_nuevo = sanitize_text_field($info_prod[0]);
                $nombre_nuevo = isset($info_prod[1]) ? sanitize_text_field($info_prod[1]) : $codigo_nuevo; 
                
                $cant_raw = preg_replace('/[^\d.,-]/', '', sanitize_text_field($nuevo['cantidad']));
                $cant_limpia = str_replace(',', '.', $cant_raw);
                $cant_sql = number_format(floatval($cant_limpia), 2, '.', '');
                
                $unidad_conteo  = sanitize_text_field($nuevo['unidad_conteo']);
                $fecha_v = !empty($nuevo['fecha_vencimiento']) ? sanitize_text_field($nuevo['fecha_vencimiento']) : null;
                
                if ( !empty($nombre_nuevo) ) {
                    $existe = $wpdb->get_var($wpdb->prepare("SELECT id FROM $tabla_items WHERE conteo_id = %d AND codigo = %s", $conteo_id, $codigo_nuevo));
                    if (!$existe) {
                        $wpdb->insert($tabla_items, array(
                            'conteo_id'         => $conteo_id,
                            'codigo'            => $codigo_nuevo,
                            'nombre_producto'   => $nombre_nuevo,
                            'cantidad_esperada' => 0.00, 
                            'unidad_sistema'    => $unidad_conteo,
                            'unidad_conteo'     => $unidad_conteo,
                            'cantidad'          => $cant_sql,
                            'fecha_vencimiento' => $fecha_v,
                            'observaciones'     => isset($nuevo['observaciones']) ? sanitize_textarea_field($nuevo['observaciones']) : ''
                        ));
                    }
                }
            }
        }

        $generar_pdf = false;
        
        if ($conteo->intento == 1) {
            $diferencias = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tabla_items WHERE conteo_id = %d AND ROUND(cantidad, 2) != ROUND(cantidad_esperada, 2)", $conteo_id));
            if ($diferencias > 0) {
                $wpdb->update($tabla_conteos, array('intento' => 2), array('id' => $conteo_id));
                $mensaje = '<div style="background-color: #fff3cd; color: #856404; padding: 25px; font-size: 20px; border-radius: 8px; margin-bottom: 30px; text-align: center; border: 2px solid #ffeeba;"><strong>⚠️ SE REQUIERE RECONTEO</strong><br><br>Se detectaron diferencias. Verifique las cantidades de los productos en pantalla.</div>';
                $mensaje .= "<script>if ( window.history.replaceState ) { window.history.replaceState( null, null, window.location.href ); }</script>";
                if ($conteo_pendiente) $conteo_pendiente->intento = 2; 
            } else {
                $generar_pdf = true;
            }
        } else {
            $generar_pdf = true;
        }

        if ($generar_pdf) {
            $punto  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabla_puntos WHERE id = %d", $conteo->punto_id));
            $productos_todos = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tabla_items WHERE conteo_id = %d", $conteo_id));
            
            $jefe_id_seguro = intval($conteo->jefe_id);
            $jefe = $jefe_id_seguro > 0 ? get_userdata($jefe_id_seguro) : false;
            
            $nombre_jefe = $jefe ? $jefe->display_name : 'No Asignado';
            $email_jefe  = $jefe ? $jefe->user_email   : 'sin-correo@sistema.local';
            
            $nombre_sede = $punto ? $punto->nombre_punto : 'Sede Desconocida';

            $ruta_fpdf = plugin_dir_path( __DIR__ ) . 'includes/fpdf/fpdf.php';
            if( file_exists($ruta_fpdf) ) {
                require_once $ruta_fpdf;
                $pdf = new FPDF();
                $pdf->SetMargins(35, 10, 10); 
                $pdf->AddPage();
                
                $pdf->SetFont('Arial', 'B', 16);
                $pdf->Cell(0, 10, utf8_decode('REPORTE OFICIAL DE INVENTARIO FÍSICO'), 0, 1, 'C');
                $pdf->Ln(5);
                $pdf->SetFont('Arial', '', 12);
                $pdf->Cell(0, 8, utf8_decode('Punto de Atención: ' . $nombre_sede), 0, 1);
                $pdf->Cell(0, 8, utf8_decode('Verificador (Elaboró): ' . $usuario_actual->display_name), 0, 1);
                $pdf->Cell(0, 8, utf8_decode('Jefe de Punto (Revisa): ' . $nombre_jefe), 0, 1);
                $pdf->Cell(0, 8, utf8_decode('Fecha de Levantamiento: ' . date('d/m/Y H:i')), 0, 1);
                $pdf->Ln(10);

                foreach($productos_todos as $p) {
                    $pdf->SetFont('Arial', 'B', 12);
                    $pdf->Cell(0, 8, utf8_decode("Producto: " . $p->nombre_producto), 0, 1);
                    $pdf->SetFont('Arial', '', 12);
                    
                    $cantidad_mostrar = floatval($p->cantidad);
                    $texto_cantidad = "Cantidad Final: " . $cantidad_mostrar . " " . $p->unidad_conteo;
                    $pdf->Cell(0, 8, utf8_decode($texto_cantidad), 0, 1);
                    
                    if(!empty($p->fecha_vencimiento)) $pdf->Cell(0, 8, utf8_decode("Fecha de Vencimiento: " . $p->fecha_vencimiento), 0, 1);
                    if(!empty($p->observaciones)) $pdf->MultiCell(0, 8, utf8_decode("Observaciones: " . $p->observaciones));
                    $pdf->Ln(4);
                    $pdf->Cell(0, 0, '', 'T'); 
                    $pdf->Ln(4);
                }

                $pdf_content = $pdf->Output('S'); 
                $pdf_base64 = base64_encode($pdf_content);

                if( function_exists('invfacil_enviar_azsign') ) {
                    $respuesta_azsign = invfacil_enviar_azsign($pdf_base64, $usuario_actual->user_email, $usuario_actual->display_name, $email_jefe, $nombre_jefe, $nombre_sede);
                    $acuerdo_id = '';
                    if(preg_match('/AcuerdoId="([^"]+)"/i', $respuesta_azsign['response'], $matches)) { $acuerdo_id = $matches[1]; }

                    $wpdb->update($tabla_conteos, array('estado' => 'enviado_azsign', 'azsign_acuerdo_id' => $acuerdo_id), array('id' => $conteo_id));
                    if(function_exists('invfacil_registrar_bitacora')) invfacil_registrar_bitacora("Finalizó el inventario #$conteo_id (Intento: $conteo->intento)");
                    
                    $mensaje = '<div style="background-color: #d4edda; color: #155724; padding: 25px; font-size: 22px; border-radius: 8px; margin-bottom: 30px; text-align: center;"><strong>¡Inventario Terminado Exitosamente!</strong><br><br>Enviado a AZSign para firmas.</div>';
                    $mensaje .= "<script>if ( window.history.replaceState ) { window.history.replaceState( null, null, window.location.href ); }</script>";
                    $conteo_pendiente = false;
                }
            }
        }
    }

    if ( ! $conteo_pendiente ) return $mensaje . '<div style="font-size: 24px; text-align:center; padding: 50px; background: #f0f6fc; border-radius: 12px; border: 2px dashed #007cba;">No tiene inventarios pendientes.<br>¡Buen trabajo!</div>';

    $info_punto = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabla_puntos WHERE id = %d", $conteo_pendiente->punto_id));
    $nombre_sede_mostrar = $info_punto ? $info_punto->nombre_punto : 'Sede Desconocida';
    
    if ($conteo_pendiente->intento == 2) {
        $items_pendientes = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tabla_items WHERE conteo_id = %d AND ROUND(cantidad, 2) != ROUND(cantidad_esperada, 2)", $conteo_pendiente->id));
        $instrucciones = "<strong>⚠️ RECONTEO REQUERIDO:</strong> Verifique únicamente estos productos.";
    } else {
        $items_pendientes = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tabla_items WHERE conteo_id = %d", $conteo_pendiente->id));
        $instrucciones = "<strong>Instrucciones:</strong> Cuente físicamente e ingrese la cantidad. Botella de 750ML equivale a 24 Tragos; Botella de 700 ML 20 Tragos<em>(Puede usar comas o puntos para los decimales)</em>.";
    }

    $productos_erp = $wpdb->get_results("SELECT codigo, nombre FROM $tabla_prod_erp ORDER BY nombre ASC");
    $opciones_unidades = '<option value="Unidad">Unidad</option><option value="Gramo">Gramo</option><option value="Libra">Libra</option><option value="Kilo">Kilo</option><option value="Mililitro">Mililitro</option><option value="Litro">Litro</option>';

    ob_start();
    ?>
    <div class="inv-facil-form">
        <?php echo $mensaje; ?>
        <h2 style="font-size: 34px; color: #135e96; text-align: center;">Punto: <?php echo esc_html($nombre_sede_mostrar); ?></h2>
        <div class="inv-instrucciones"><?php echo $instrucciones; ?></div>
        
        <div class="sticky-search">
            <input type="text" id="buscador-inventario" class="inv-buscador" onkeyup="filtrarInventario()" placeholder="🔍 Buscar producto...">
        </div>
        
        <form method="post" action="" onsubmit="return procesarParacaidasYEnviar(event, this);">
            <input type="hidden" name="conteo_id" value="<?php echo esc_attr($conteo_pendiente->id); ?>">
            <input type="hidden" name="intento_actual" value="<?php echo esc_attr($conteo_pendiente->intento); ?>">
            
            <div id="contenedor-items-existentes">
                <?php if($items_pendientes): foreach ( $items_pendientes as $item ) : ?>
                    <div class="inv-caja-producto caja-item-filtro" data-nombre="<?php echo esc_attr(strtolower($item->nombre_producto)); ?>">
                        <input type="hidden" name="items[<?php echo $item->id; ?>][codigo]" value="<?php echo esc_attr($item->codigo); ?>">
                        
                        <span class="inv-titulo-prod">📦 <?php echo esc_html($item->nombre_producto); ?></span>
                        
                        <div style="display: flex; gap: 15px; margin-top: 15px;">
                            <div style="flex: 2;">
                                <label>Cantidad Físicamente Contada <span class="badge-req">*</span></label>
                                <input type="text" inputmode="decimal" pattern="[0-9.,]+" title="Solo números, puntos o comas" name="items[<?php echo $item->id; ?>][cantidad]" required value="<?php echo esc_attr(floatval($item->cantidad)); ?>" onfocus="if(this.value=='0') this.value='';" onblur="if(this.value=='') this.value='0';">
                            </div>
                            <div style="flex: 1;">
                                <label>En (Unidad) <span class="badge-req">*</span></label>
                                <select name="items[<?php echo $item->id; ?>][unidad_conteo]" required>
                                    <?php echo invfacil_generar_opciones_unidad($item->unidad_sistema, $item->unidad_conteo); ?>
                                </select>
                            </div>
                        </div>

                        <div style="display: flex; gap: 15px; margin-top: 15px;">
                            <div style="flex: 1;">
                                <label>Fecha de Vencimiento</label>
                                <input type="date" name="items[<?php echo $item->id; ?>][fecha_vencimiento]" value="<?php echo esc_attr($item->fecha_vencimiento); ?>">
                            </div>
                        </div>
                        
                        <label>Observaciones</label>
                        <textarea name="items[<?php echo $item->id; ?>][observaciones]" rows="2"><?php echo esc_html($item->observaciones); ?></textarea>
                    </div>
                <?php endforeach; else: ?>
                    <div style="padding: 20px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 8px; margin-bottom: 20px; text-align: center; font-size: 18px;">
                        No hay productos precargados o ya no existen diferencias.
                    </div>
                <?php endif; ?>
            </div>

            <div id="contenedor-nuevos-productos"></div>
            <button type="button" class="inv-btn-agregar" onclick="agregarNuevoProducto()">➕ Agregar Producto No Listado</button>
            <datalist id="lista_prod_erp"><?php foreach($productos_erp as $p) echo "<option value='" . esc_attr($p->codigo . " - " . $p->nombre) . "'>"; ?></datalist>
            
            <button type="submit" class="inv-btn-enviar">Guardar / Verificar Inventario</button>
        </form>
    </div>

    <script>
        // CAPTURADOR GLOBAL DE ERRORES JS
        window.onerror = function(mensaje, origen, linea, columna, error) {
            if (typeof invfacil_ajax !== 'undefined') {
                let errorData = new FormData();
                errorData.append('action', 'invfacil_log_js_error');
                errorData.append('error_msg', mensaje + " en linea " + linea);
                fetch(invfacil_ajax.url, { method: 'POST', body: errorData }).catch(e => {});
            }
        };

        function filtrarInventario() {
            let filtro = document.getElementById('buscador-inventario').value.toLowerCase();
            let cajas = document.getElementsByClassName('caja-item-filtro');
            for (let i = 0; i < cajas.length; i++) {
                let nombreProd = cajas[i].getAttribute('data-nombre');
                cajas[i].style.display = nombreProd.includes(filtro) ? "" : "none";
            }
        }

        let nuevoIndex = 0;
        function agregarNuevoProducto() {
            nuevoIndex++;
            let html = `
            <div class="inv-caja-producto caja-item-filtro" data-nombre="nuevo" style="border-color: #007cba; background: #f8fbff;">
                <span class="inv-titulo-prod" style="color: #007cba;">✨ Nuevo Producto</span>
                <label>Nombre del Producto *</label>
                <input type="text" name="nuevos_items[${nuevoIndex}][nombre_producto]" list="lista_prod_erp" required placeholder="Buscar en el ERP...">
                <div style="display: flex; gap: 15px;">
                    <div style="flex: 2;">
                        <label>Cantidad contada *</label>
                        <input type="text" inputmode="decimal" pattern="[0-9.,]+" title="Solo números, puntos o comas" name="nuevos_items[${nuevoIndex}][cantidad]" required value="0" onfocus="if(this.value=='0') this.value='';" onblur="if(this.value=='') this.value='0';">
                    </div>
                    <div style="flex: 1;">
                        <label>Unidad *</label>
                        <select name="nuevos_items[${nuevoIndex}][unidad_conteo]" required>
                            <option value="">-- Elija --</option>
                            <?php echo $opciones_unidades; ?>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 15px; margin-top: 15px;">
                    <div style="flex: 1;">
                        <label>Fecha de Vencimiento</label>
                        <input type="date" name="nuevos_items[${nuevoIndex}][fecha_vencimiento]">
                    </div>
                </div>
                <label>Observaciones</label>
                <textarea name="nuevos_items[${nuevoIndex}][observaciones]" rows="2"></textarea>
                <button type="button" onclick="this.parentElement.remove(); guardarBorrador();" style="margin-top:15px; background:#d63638; color:#fff; border:none; padding:10px 20px; border-radius:6px; cursor:pointer; font-size:18px;">❌ Eliminar</button>
            </div>`;
            document.getElementById('contenedor-nuevos-productos').insertAdjacentHTML('beforeend', html);
            guardarBorrador();
        }

        function guardarBorrador() {
            let conteoId = document.querySelector('input[name="conteo_id"]').value;
            let htmlNuevos = document.getElementById('contenedor-nuevos-productos').innerHTML;
            let inputs = {};
            document.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(el => { inputs[el.name] = el.value; });
            let borrador = { html: htmlNuevos, data: inputs, index: nuevoIndex };
            localStorage.setItem('invfacil_borrador_' + conteoId, JSON.stringify(borrador));
        }

        function cargarBorrador() {
            let conteoId = document.querySelector('input[name="conteo_id"]').value;
            let borrador = localStorage.getItem('invfacil_borrador_' + conteoId);
            if (borrador) {
                borrador = JSON.parse(borrador);
                if(borrador.html) {
                    document.getElementById('contenedor-nuevos-productos').innerHTML = borrador.html;
                    nuevoIndex = borrador.index || 0;
                }
                if(borrador.data) {
                    for(let key in borrador.data) {
                        let el = document.querySelector('[name="' + key + '"]');
                        if (el) el.value = borrador.data[key];
                    }
                }
                let aviso = document.createElement('div');
                aviso.style = "background: #d4edda; color: #155724; padding: 15px; text-align: center; border-radius: 8px; margin-bottom: 20px; font-weight: bold; border: 1px solid #c3e6cb;";
                aviso.innerHTML = "💾 Sistema de Recuperación: Borrador restaurado automáticamente.";
                document.querySelector('.inv-instrucciones').after(aviso);
                setTimeout(() => aviso.remove(), 6000);
            }
        }

        document.addEventListener("DOMContentLoaded", function() {
            cargarBorrador();
            let form = document.querySelector('.inv-facil-form form');
            form.addEventListener('input', guardarBorrador);
            form.addEventListener('change', guardarBorrador);
        });

        // PARACAÍDAS JS: 2 PASOS + JSON MALETA
        function procesarParacaidasYEnviar(e, form) {
            e.preventDefault(); 
            
            // 🚀 VALIDACIÓN ANTI-CEROS
            let sumaCantidades = 0;
            let inputsCantidad = form.querySelectorAll('input[name*="[cantidad]"]');
            
            inputsCantidad.forEach(inp => {
                let val = parseFloat(inp.value.replace(',', '.').replace(/[^\d.-]/g, ''));
                if(!isNaN(val)) { sumaCantidades += val; }
            });

            if (sumaCantidades <= 0) {
                alert('🛡️ BLOQUEO DE SEGURIDAD: El inventario está completamente vacío (Suma total = 0). Para proteger la base de datos, no se puede enviar. Si hay un error de visualización, use el buscador.');
                return false;
            }

            if(!confirm('¿Revisó bien las cantidades?')) { return false; }
            
            let btn = form.querySelector('.inv-btn-enviar');
            btn.style.display = 'none';
            
            let intentoAct = form.querySelector('input[name="intento_actual"]').value;
            let csv = "\uFEFFID_Item,Codigo,Nombre,Cantidad,Unidad,Fecha,Observaciones,Intento\n"; 
            
            let items = form.querySelectorAll('.caja-item-filtro');
            items.forEach(item => {
                let nEl = item.querySelector('.inv-titulo-prod');
                let nombre = nEl ? nEl.innerText.replace('📦 ','').replace('✨ ','') : '';
                let inputs = item.querySelectorAll('input, select, textarea');
                let d = {cod:'', cant:'', und:'', f:'', obs:'', id:''};
                
                inputs.forEach(i => {
                    if(i.name.includes('[codigo]')) d.cod = i.value;
                    else if(i.name.includes('[cantidad]')) d.cant = i.value;
                    else if(i.name.includes('[unidad_conteo]')) d.und = i.value;
                    else if(i.name.includes('[fecha_vencimiento]')) d.f = i.value;
                    else if(i.name.includes('[observaciones]')) d.obs = i.value;
                    let m = i.name.match(/items\[(\d+)\]/);
                    if(m) d.id = m[1]; 
                });
                
                let cNom = '"' + nombre.replace(/"/g, '""') + '"';
                let cObs = '"' + d.obs.replace(/"/g, '""') + '"';
                csv += `${d.id},"${d.cod}",${cNom},"${d.cant}","${d.und}","${d.f}",${cObs},"${intentoAct}"\n`;
            });
            
            let blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            let url = URL.createObjectURL(blob);
            let pId = form.querySelector('input[name="conteo_id"]').value;
            let filename = `Respaldo_Inv_${pId}_Intento_${intentoAct}.csv`;
            
            let uiHtml = `
            <div id="caja-rescate-movil" style="background: #e0f2fe; border: 2px solid #bae6fd; padding: 20px; border-radius: 8px; margin-top: 20px; text-align: center;">
                <h3 style="color: #0284c7; margin-top: 0; font-size: 22px;">🛡️ Paso de Seguridad Requerido</h3>
                <p style="color: #0369a1; font-size: 16px; margin-bottom: 20px;">Para evitar pérdida de datos si falla el internet, descarga tu respaldo antes de enviar.</p>
                <a href="${url}" download="${filename}" onclick="document.getElementById('btn-enviar-final').style.display='block'; this.innerHTML='✅ Respaldo Descargado'; this.style.background='#10b981';" style="display: block; background: #0ea5e9; color: #fff; padding: 18px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 18px; margin-bottom: 15px; box-sizing: border-box; transition: 0.3s;">1. 📥 Toca aquí para Descargar Respaldo</a>
                <button type="button" id="btn-enviar-final" onclick="ejecutarEnvioFinal(this, this.closest('form'))" style="display: none; background: #135e96; color: #fff; padding: 18px; border: none; border-radius: 8px; font-weight: bold; font-size: 18px; width: 100%; cursor: pointer; box-sizing: border-box; transition: 0.3s;">2. 📤 Enviar al Sistema</button>
            </div>`;
            btn.insertAdjacentHTML('afterend', uiHtml);
        }

        window.ejecutarEnvioFinal = function(btn, form) {
            btn.innerHTML = '⏳ Transmitiendo datos...';
            btn.style.pointerEvents = 'none';
            btn.style.opacity = '0.7';
            document.getElementById('caja-rescate-movil').style.opacity = '0.7';

            let pId = form.querySelector('input[name="conteo_id"]').value;
            localStorage.removeItem('invfacil_borrador_' + pId);
            window.onbeforeunload = null;

            let itemsParaEnviar = {};
            let items = form.querySelectorAll('.caja-item-filtro');
            
            items.forEach(item => {
                let idMatch = item.innerHTML.match(/name="items\[(\d+)\]\[codigo\]"/);
                if(idMatch) {
                    let idReal = idMatch[1];
                    itemsParaEnviar[idReal] = {
                        codigo: item.querySelector(`[name="items[${idReal}][codigo]"]`)?.value || '',
                        cantidad: item.querySelector(`[name="items[${idReal}][cantidad]"]`)?.value || '0',
                        unidad_conteo: item.querySelector(`[name="items[${idReal}][unidad_conteo]"]`)?.value || 'Unidad',
                        fecha_vencimiento: item.querySelector(`[name="items[${idReal}][fecha_vencimiento]"]`)?.value || '',
                        observaciones: item.querySelector(`[name="items[${idReal}][observaciones]"]`)?.value || ''
                    };
                    
                    item.querySelectorAll('input, select, textarea').forEach(inp => {
                        if(inp.name.includes('items[')) inp.disabled = true;
                    });
                }
            });

            let maletaInput = document.createElement('input');
            maletaInput.type = 'hidden';
            maletaInput.name = 'maleta_json';
            maletaInput.value = JSON.stringify(itemsParaEnviar);
            form.appendChild(maletaInput);

            setTimeout(() => {
                let hInput = document.createElement('input');
                hInput.type = 'hidden';
                hInput.name = 'invfacil_enviar_firma';
                hInput.value = '1';
                form.appendChild(hInput);
                HTMLFormElement.prototype.submit.call(form);
            }, 300);
        }
    </script>
    <?php
    return ob_get_clean();
}

// ========================================================================
// SHORTCODE 2: JEFE DE PUNTO [historico_inventarios]
// ========================================================================
add_shortcode( 'historico_inventarios', 'invfacil_shortcode_historico' );

function invfacil_shortcode_historico() {
    if ( ! is_user_logged_in() ) return '<p style="text-align:center; font-size:20px;">Debe iniciar sesión.</p>';
    $usuario_actual = wp_get_current_user();
    if ( ! in_array( 'invfacil_jefe', (array) $usuario_actual->roles ) ) return '<p style="text-align:center; font-size:20px;">Solo los Jefes de Punto pueden ver esta sección.</p>';

    global $wpdb;
    $tabla_conteos = $wpdb->prefix . 'invfacil_conteos';
    $tabla_puntos  = $wpdb->prefix . 'invfacil_puntos';
    $historicos = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tabla_conteos WHERE jefe_id = %d AND estado = 'enviado_azsign' ORDER BY fecha_asignacion DESC", $usuario_actual->ID));

    ob_start();
    ?>
    <div class="inv-hist-wrap">
        <h2>Histórico de Inventarios Firmados</h2>
        <p style="font-size: 20px; text-align:center; color:#555; margin-bottom: 30px;">
            Bienvenido, <?php echo esc_html($usuario_actual->display_name); ?>. Aquí puede descargar los documentos oficiales.
        </p>

        <?php if($historicos): foreach($historicos as $h): 
            $pto = $wpdb->get_row($wpdb->prepare("SELECT nombre_punto FROM $tabla_puntos WHERE id = %d", $h->punto_id));
        ?>
            <div class="inv-tarjeta">
                <div class="inv-tarjeta-info">
                    <h3>Sede: <?php echo $pto ? esc_html($pto->nombre_punto) : 'Desconocida'; ?></h3>
                    <p><strong>Fecha Asignación:</strong> <?php echo date('d/m/Y', strtotime($h->fecha_asignacion)); ?></p>
                </div>
                <div>
                    <a href="<?php echo esc_url(add_query_arg('descargar_pdf_azsign', $h->id, site_url())); ?>" class="inv-btn-descarga" target="_blank">📥 Descargar PDF</a>
                </div>
            </div>
        <?php endforeach; else: ?>
            <div style="font-size: 22px; text-align:center; padding: 30px; border: 2px dashed #ccc; border-radius: 10px;">
                No hay inventarios terminados para su revisión aún.
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ========================================================================
// SHORTCODE 3: TRASLADOS [crear_traslado] CON FIRMAS EN PAD Y FORMATO REPARADO
// ========================================================================
add_shortcode( 'crear_traslado', 'invfacil_shortcode_crear_traslado' );

function invfacil_shortcode_crear_traslado() {
    if ( ! is_user_logged_in() ) return '<div class="inv-msj-warn"><p>Debe iniciar sesión.</p></div>';

    global $wpdb;
    $usuario_actual       = wp_get_current_user();
    $tabla_bodegas        = $wpdb->prefix . 'invfacil_bodegas';
    $tabla_prod_erp       = $wpdb->prefix . 'invfacil_productos_erp';
    $tabla_traslados      = $wpdb->prefix . 'invfacil_traslados';
    $tabla_traslado_items = $wpdb->prefix . 'invfacil_traslado_items';
    $mensaje              = '';

    static $traslado_procesado = false;

if ( isset($_POST['invfacil_cerrar_traslado']) && !$traslado_procesado ) {
        $traslado_procesado = true;
        
        $origen_raw  = explode(' - ', sanitize_text_field($_POST['bodega_origen']));
        $destino_raw = explode(' - ', sanitize_text_field($_POST['bodega_destino']));
        $motivo      = sanitize_text_field($_POST['motivo']);
        $origen_id   = intval($origen_raw[0]);
        $destino_id  = intval($destino_raw[0]);
        
        $firma_e = isset($_POST['firma_entrega_b64']) ? $_POST['firma_entrega_b64'] : '';
        $firma_v = isset($_POST['firma_verifica_b64']) ? $_POST['firma_verifica_b64'] : '';
        $nombre_e = sanitize_text_field($_POST['nombre_entrega']);
        $nombre_v = sanitize_text_field($_POST['nombre_recibe']);

        // 🚀 Determinamos el nombre real de las bodegas ANTES de guardar
        $n_origen = $wpdb->get_var($wpdb->prepare("SELECT nombre_bodega FROM $tabla_bodegas WHERE id = %d", $origen_id));
        if (empty($n_origen)) $n_origen = isset($origen_raw[1]) ? trim($origen_raw[1]) : trim($origen_raw[0]);

        $n_destino = $wpdb->get_var($wpdb->prepare("SELECT nombre_bodega FROM $tabla_bodegas WHERE id = %d", $destino_id));
        if (empty($n_destino)) $n_destino = isset($destino_raw[1]) ? trim($destino_raw[1]) : trim($destino_raw[0]);

        if(empty($firma_e) || strlen($firma_e) < 1000 || empty($firma_v) || strlen($firma_v) < 1000 || empty($nombre_e) || empty($nombre_v)) {
            $mensaje = '<div class="inv-msj-warn" style="margin-bottom:20px; padding:15px; border-left:4px solid #f59e0b; background:#fffbeb;">⚠️ Operación denegada: Ambas firmas y los nombres correspondientes son obligatorios. Asegúrese de completar todos los campos.</div>';
        } else {
            $wpdb->insert($tabla_traslados, array(
                'bodega_origen_id'      => $origen_id,
                'bodega_destino_id'     => $destino_id,
                'bodega_origen_nombre'  => $n_origen, // Se inserta en la BD
                'bodega_destino_nombre' => $n_destino, // Se inserta en la BD
                'motivo'                => $motivo, // Se inserta en la BD
                'tipo_salida'           => '510 TRASLADO SALIDA ENTRE BODEGAS',
                'tipo_entrada'          => '501 TRASLADO ENTRADA ENTRE BODEGAS',
                'estado'                => 'pendiente_erp', 
                'elaborador_id'         => $usuario_actual->ID,
                'firma_entrega'         => $firma_e,
                'firma_verifica'        => $firma_v,
                'nombre_entrega'        => $nombre_e,
                'nombre_recibe'         => $nombre_v
            ));
            $traslado_id = $wpdb->insert_id;
            
            $productos_pdf = array();
            if ( isset($_POST['productos']) && is_array($_POST['productos']) ) {
                foreach($_POST['productos'] as $prod) {
                    $info_prod = explode(' - ', sanitize_text_field($prod['info']), 2);
                    $codigo    = sanitize_text_field($info_prod[0]);
                    $nombre    = isset($info_prod[1]) ? sanitize_text_field($info_prod[1]) : '';
                    
                    $cant_limpia = str_replace(',', '.', sanitize_text_field($prod['cantidad']));
                    $cantidad = floatval($cant_limpia);
                    
                    $unidad    = sanitize_text_field($prod['unidad']);

                    if(!empty($codigo) && $cantidad > 0) {
                        $wpdb->insert($tabla_traslado_items, array(
                            'traslado_id'     => $traslado_id,
                            'producto_codigo' => $codigo,
                            'producto_nombre' => $nombre,
                            'cantidad'        => $cantidad,
                            'unidad_medida'   => $unidad
                        ));
                        $productos_pdf[] = array('codigo' => $codigo, 'nombre' => $nombre, 'cantidad' => $cantidad, 'unidad' => $unidad);
                    }
                }
            }

            require_once plugin_dir_path( __DIR__ ) . 'includes/fpdf/fpdf.php';
            $pdf = new FPDF('P', 'mm', 'A4');
            $pdf->SetMargins(35, 10, 10); 
            $pdf->AddPage();
            
            // 🚀 Lógica de rescate de Nombres (Fallback)
            $n_origen = $wpdb->get_var($wpdb->prepare("SELECT nombre_bodega FROM $tabla_bodegas WHERE id = %d", $origen_id));
            if (empty($n_origen)) $n_origen = isset($origen_raw[1]) ? trim($origen_raw[1]) : trim($origen_raw[0]);

            $n_destino = $wpdb->get_var($wpdb->prepare("SELECT nombre_bodega FROM $tabla_bodegas WHERE id = %d", $destino_id));
            if (empty($n_destino)) $n_destino = isset($destino_raw[1]) ? trim($destino_raw[1]) : trim($destino_raw[0]);

            // Truncado protector a 42 caracteres para evitar desbordes
            $n_origen_print = strlen($n_origen) > 42 ? substr($n_origen, 0, 42) . '...' : $n_origen;
            $n_destino_print = strlen($n_destino) > 42 ? substr($n_destino, 0, 42) . '...' : $n_destino;

            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(35, 15, 'CLUB MILITAR', 1, 0, 'C');
            $pdf->Cell(80, 15, 'REPORTE TRASLADO DE INVENTARIOS', 1, 0, 'C');
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $pdf->SetFont('Arial', '', 8);
            $pdf->MultiCell(50, 5, utf8_decode("CÓDIGO: AB-P10-F02\nVERSION: 1\nFECHA: 25/01/2022"), 1, 'L');
            $pdf->SetXY($pdf->GetX(), $y + 15);
            
            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(25, 8, 'MOTIVO:', 1, 0, 'C');
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(90, 8, utf8_decode($motivo), 1, 0, 'L');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(20, 8, 'FECHA:', 1, 0, 'C');
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(30, 8, date('d/m/Y'), 1, 1, 'C');

            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(165, 8, 'MOVIMIENTO DE SALIDA', 1, 1, 'C');
            $pdf->Cell(82.5, 8, '510 TRASLADO SALIDA ENTRE BODEGAS', 1, 0, 'C');
            $pdf->Cell(82.5, 8, '501 TRASLADO ENTRADA ENTRE BODEGAS', 1, 1, 'C');
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(82.5, 8, utf8_decode($n_origen_print), 1, 0, 'C');
            $pdf->Cell(82.5, 8, utf8_decode($n_destino_print), 1, 1, 'C');

            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(25, 8, 'CODIGO', 1, 0, 'C');
            $pdf->Cell(70, 8, 'PRODUCTO', 1, 0, 'C');
            $pdf->Cell(15, 8, 'CANT.', 1, 0, 'C');
            $pdf->Cell(25, 8, 'UND.MEDIDA', 1, 0, 'C');
            $pdf->Cell(30, 8, 'TOTAL', 1, 1, 'C');

            $pdf->SetFont('Arial', '', 8);
            foreach($productos_pdf as $p) {
                $pdf->Cell(25, 8, utf8_decode($p['codigo']), 1, 0, 'C');
                $nombre_corto = strlen($p['nombre']) > 40 ? substr($p['nombre'], 0, 40) . '...' : $p['nombre'];
                $pdf->Cell(70, 8, utf8_decode($nombre_corto), 1, 0, 'L');
                $pdf->Cell(15, 8, $p['cantidad'], 1, 0, 'C');
                $pdf->Cell(25, 8, utf8_decode($p['unidad']), 1, 0, 'C');
                $pdf->Cell(30, 8, '', 1, 1, 'C');
            }

            $pdf->Ln(10);
            $x_inicial = $pdf->GetX();
            $y_inicial = $pdf->GetY();
            
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(82.5, 8, utf8_decode('FIRMA DE QUIEN ENTREGA:'), 0, 0, 'C');
            if (!empty($firma_e)) {
                $img_e = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $firma_e));
                $tmp_e = sys_get_temp_dir() . '/f_e_traslado_' . $traslado_id . '_' . uniqid() . '.png';
                file_put_contents($tmp_e, $img_e);
                $pdf->Image($tmp_e, $x_inicial + 15, $y_inicial + 8, 50, 20);
                unlink($tmp_e);
            }
            
            $pdf->SetXY($x_inicial + 82.5, $y_inicial);
            $pdf->Cell(82.5, 8, utf8_decode('FIRMA DE QUIEN RECIBE:'), 0, 1, 'C');
            if (!empty($firma_v)) {
                $img_v = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $firma_v));
                $tmp_v = sys_get_temp_dir() . '/f_v_traslado_' . $traslado_id . '_' . uniqid() . '.png';
                file_put_contents($tmp_v, $img_v);
                $pdf->Image($tmp_v, $x_inicial + 82.5 + 15, $y_inicial + 8, 50, 20);
                unlink($tmp_v);
            }
            
            // Impresión dinámica de los nombres digitados en el formulario
            $pdf->SetY($y_inicial + 30);
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(82.5, 8, utf8_decode('Entregado por: ' . $nombre_e), 'T', 0, 'C');
            $pdf->Cell(82.5, 8, utf8_decode('Recibido por: ' . $nombre_v), 'T', 1, 'C');

            $pdf_content = $pdf->Output('S'); 
            $pdf_base64 = base64_encode($pdf_content);
            
            if(function_exists('invfacil_registrar_bitacora')) invfacil_registrar_bitacora("Elaboró el Traslado ID: #TR-$traslado_id (De '$n_origen' a '$n_destino')");

            $mensaje = '
            <div style="background-color: #d4edda; color: #155724; padding: 20px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                <strong>¡Traslado Guardado y Firmado Exitosamente!</strong><br>
                A continuación puedes previsualizar el PDF generado.
            </div>
            <iframe src="data:application/pdf;base64,'.$pdf_base64.'" width="100%" height="600px" style="border: 2px solid #ccc; border-radius: 8px; margin-bottom:30px;"></iframe>';
            $mensaje .= "<script>if ( window.history.replaceState ) { window.history.replaceState( null, null, window.location.href ); }</script>";
        }
    }

    $bodegas = $wpdb->get_results("SELECT * FROM $tabla_bodegas ORDER BY nombre_bodega ASC");
    $productos_erp = $wpdb->get_results("SELECT codigo, nombre FROM $tabla_prod_erp ORDER BY nombre ASC");

    ob_start();
    ?>
    <div class="inv-traslado-form">
        <?php echo $mensaje; ?>
        <h2>Crear Traslado de Inventario</h2>
        
        <form method="post" action="" id="formTraslado" onsubmit="return validarAmbasFirmasTraslado(event);">
            <div class="inv-seccion">
                <h3>1. Origen y Destino</h3>
                <div class="inv-row">
                    <div>
                        <label>510 TRASLADO SALIDA ENTRE BODEGAS (Origen)</label>
                        <input type="text" name="bodega_origen" list="lista_bodegas" required class="front-input">
                    </div>
                    <div>
                        <label>501 TRASLADO ENTRADA ENTRE BODEGAS (Destino)</label>
                        <input type="text" name="bodega_destino" list="lista_bodegas" required class="front-input">
                    </div>
                </div>
                <div class="inv-row">
                    <div><label>Motivo</label><input type="text" name="motivo" required class="front-input" placeholder="Ej: Traslado por Reabastecimiento"></div>
                </div>
            </div>

            <div class="inv-seccion">
                <h3>2. Productos a Trasladar</h3>
                <div id="traslado-items-container">
                    <div class="inv-row-producto">
                        <div style="flex: 2;"><label>Producto (Código - Nombre)</label><input type="text" name="productos[0][info]" list="lista_productos_erp" required class="front-input"></div>
                        <div style="flex: 1;"><label>Cantidad</label><input type="text" inputmode="decimal" pattern="[0-9.,]+" name="productos[0][cantidad]" required class="front-input"></div>
                        <div style="flex: 1;"><label>Und. Medida</label><input type="text" name="productos[0][unidad]" list="lista_unidades" required class="front-input"></div>
                        <div style="flex: 0.5;"></div>
                    </div>
                </div>
                <button type="button" class="inv-btn-agregar" onclick="agregarFilaTraslado()">➕ Agregar Otro Producto</button>
            </div>

            <div class="inv-seccion">
                <h3>3. Firmas Digitales</h3>
                <p style="color:#64748b; font-size:14px; margin-bottom:20px;">Ambas firmas y nombres son obligatorios para autorizar y generar el PDF oficial de este traslado.</p>
                <input type="hidden" name="firma_entrega_b64" id="firma_entrega_b64">
                <input type="hidden" name="firma_verifica_b64" id="firma_verifica_b64">

                <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 25px;">
                    <div style="flex: 1; min-width: 280px; text-align: center; border:2px solid #cbd5e1; background:#f8fafc; border-radius:8px; padding:15px;">
                        <h3 style="color:#1e293b; margin-top:0;">✍️ Firma: Quien Entrega</h3>
                        <label style="display:block; text-align:left; font-size:14px; font-weight:bold; margin-bottom:5px;">Nombre de quien entrega:</label>
                        <input type="text" name="nombre_entrega" required class="front-input" placeholder="Nombre completo" style="margin-bottom: 10px;">
                        <canvas id="padEntrega" width="400" height="150" style="border: 2px dashed #94a3b8; background: #fff; border-radius: 8px; cursor: crosshair; touch-action: none; max-width:100%; width:100%;"></canvas>
                        <br><button type="button" onclick="clearPadE()" style="margin-top:10px; padding:6px 15px; background:#cbd5e1; border:none; border-radius:4px; cursor:pointer;">🧹 Limpiar</button>
                    </div>

                    <div style="flex: 1; min-width: 280px; text-align: center; border:2px solid #cbd5e1; background:#f8fafc; border-radius:8px; padding:15px;">
                        <h3 style="color:#1e293b; margin-top:0;">✍️ Firma: Quien Recibe</h3>
                        <label style="display:block; text-align:left; font-size:14px; font-weight:bold; margin-bottom:5px;">Nombre de quien recibe:</label>
                        <input type="text" name="nombre_recibe" required class="front-input" placeholder="Nombre completo" style="margin-bottom: 10px;">
                        <canvas id="padVerifica" width="400" height="150" style="border: 2px dashed #94a3b8; background: #fff; border-radius: 8px; cursor: crosshair; touch-action: none; max-width:100%; width:100%;"></canvas>
                        <br><button type="button" onclick="clearPadV()" style="margin-top:10px; padding:6px 15px; background:#cbd5e1; border:none; border-radius:4px; cursor:pointer;">🧹 Limpiar</button>
                    </div>
                </div>
            </div>

            <button type="submit" name="invfacil_cerrar_traslado" class="inv-btn-cerrar">✅ Generar y Firmar Traslado</button>

            <datalist id="lista_bodegas"><?php foreach($bodegas as $b) echo "<option value='" . esc_attr($b->id . " - " . $b->nombre_bodega) . "'>"; ?></datalist>
            <datalist id="lista_productos_erp"><?php foreach($productos_erp as $p) echo "<option value='" . esc_attr($p->codigo . " - " . $p->nombre) . "'>"; ?></datalist>
            <datalist id="lista_unidades"><option value="Unidad"><option value="Gramo"><option value="Libra"><option value="Kilo"><option value="Mililitro"><option value="Litro"></datalist>
        </form>
    </div>

    <script>
        let prodIndex = 1;
        function agregarFilaTraslado() {
            const container = document.getElementById('traslado-items-container');
            const row = document.createElement('div');
            row.className = 'inv-row-producto';
            row.innerHTML = `
                <div style="flex: 2;"><label>Producto</label><input type="text" name="productos[${prodIndex}][info]" list="lista_productos_erp" required class="front-input"></div>
                <div style="flex: 1;"><label>Cantidad</label><input type="text" inputmode="decimal" pattern="[0-9.,]+" name="productos[${prodIndex}][cantidad]" required class="front-input"></div>
                <div style="flex: 1;"><label>Und. Medida</label><input type="text" name="productos[${prodIndex}][unidad]" list="lista_unidades" required class="front-input"></div>
                <div style="flex: 0.5; text-align: center; align-self: flex-end;"><button type="button" onclick="this.parentElement.parentElement.remove()" style="background:#d63638; color:#fff; border:none; padding:12px; border-radius:6px; cursor:pointer;">❌</button></div>
            `;
            container.appendChild(row);
            prodIndex++;
        }

        // Lógica de captura y validación de las Firmas
        const canvasE = document.getElementById('padEntrega');
        let drawnPixelsE = 0;
        if (canvasE) {
            const ctxE = canvasE.getContext('2d');
            let drawingE = false;
            function getPosE(e) { const r = canvasE.getBoundingClientRect(); const cx = e.clientX || (e.touches && e.touches[0].clientX); const cy = e.clientY || (e.touches && e.touches[0].clientY); return { x: cx - r.left, y: cy - r.top }; }
            canvasE.addEventListener('mousedown', (e) => { drawingE = true; drawE(e); });
            canvasE.addEventListener('mouseup', () => { drawingE = false; ctxE.beginPath(); });
            canvasE.addEventListener('mousemove', drawE);
            canvasE.addEventListener('touchstart', (e) => { drawingE = true; drawE(e); }, {passive: false});
            canvasE.addEventListener('touchend', () => { drawingE = false; ctxE.beginPath(); });
            canvasE.addEventListener('touchmove', drawE, {passive: false});

            function drawE(e) {
                if (!drawingE) return; e.preventDefault(); const p = getPosE(e);
                ctxE.lineWidth = 3; ctxE.lineCap = 'round'; ctxE.strokeStyle = '#1e293b';
                ctxE.lineTo(p.x, p.y); ctxE.stroke(); ctxE.beginPath(); ctxE.moveTo(p.x, p.y);
                drawnPixelsE++;
            }
            window.clearPadE = function() { ctxE.clearRect(0, 0, canvasE.width, canvasE.height); drawnPixelsE = 0; }
        }

        const canvasV = document.getElementById('padVerifica');
        let drawnPixelsV = 0;
        if (canvasV) {
            const ctxV = canvasV.getContext('2d');
            let drawingV = false;
            function getPosV(e) { const r = canvasV.getBoundingClientRect(); const cx = e.clientX || (e.touches && e.touches[0].clientX); const cy = e.clientY || (e.touches && e.touches[0].clientY); return { x: cx - r.left, y: cy - r.top }; }
            canvasV.addEventListener('mousedown', (e) => { drawingV = true; drawV(e); });
            canvasV.addEventListener('mouseup', () => { drawingV = false; ctxV.beginPath(); });
            canvasV.addEventListener('mousemove', drawV);
            canvasV.addEventListener('touchstart', (e) => { drawingV = true; drawV(e); }, {passive: false});
            canvasV.addEventListener('touchmove', drawV, {passive: false});

            function drawV(e) {
                if (!drawingV) return; e.preventDefault(); const p = getPosV(e);
                ctxV.lineWidth = 3; ctxV.lineCap = 'round'; ctxV.strokeStyle = '#1e293b';
                ctxV.lineTo(p.x, p.y); ctxV.stroke(); ctxV.beginPath(); ctxV.moveTo(p.x, p.y);
                drawnPixelsV++;
            }
            window.clearPadV = function() { ctxV.clearRect(0, 0, canvasV.width, canvasV.height); drawnPixelsV = 0; }
        }

        window.validarAmbasFirmasTraslado = function(e) {
            if (drawnPixelsE < 35 || drawnPixelsV < 35) {
                e.preventDefault();
                alert("⚠️ OPERACIÓN DENEGADA: Ambas firmas son obligatorias para generar el traslado.");
                return false;
            }
            document.getElementById('firma_entrega_b64').value = canvasE.toDataURL('image/png');
            document.getElementById('firma_verifica_b64').value = canvasV.toDataURL('image/png');
            
            let btn = e.target.querySelector('.inv-btn-cerrar');
            btn.innerHTML = '⏳ Generando PDF Firmado...';
            btn.style.pointerEvents = 'none';
            btn.style.opacity = '0.7';
            return true;
        }
    </script>    
    <?php
    return ob_get_clean();
}

// ========================================================================
// [panel_admin_inventario] - VISTA INTEGRADA CON PESTAÑAS
// ========================================================================
add_shortcode( 'panel_admin_inventario', 'invfacil_shortcode_admin_tabs' );

function invfacil_shortcode_admin_tabs() {
    return invfacil_render_panel_full('admin');
}

// ========================================================================
// [panel_auditor_inventario] - VISTA INTEGRADA + BITÁCORA
// ========================================================================
add_shortcode( 'panel_auditor_inventario', 'invfacil_shortcode_auditor_tabs' );

function invfacil_shortcode_auditor_tabs() {
    return invfacil_render_panel_full('auditor');
}

// FUNCIÓN MAESTRA QUE RENDERIZA AMBOS PANELES
function invfacil_render_panel_full($modo = 'admin') {
    if ( ! is_user_logged_in() ) return '<div style="text-align:center; padding: 20px;">Inicie sesión.</div>';
    
    $user = wp_get_current_user();
    $verificadores = get_users(array());
    $es_admin = in_array('invfacil_admin', (array)$user->roles) || current_user_can('manage_options');
    $es_auditor = in_array('invfacil_auditor', (array)$user->roles);

    if ($modo == 'admin' && !$es_admin) return 'Acceso denegado.';
    if ($modo == 'auditor' && !$es_auditor && !current_user_can('manage_options')) return 'Acceso denegado.';

    global $wpdb;
    $t_puntos    = $wpdb->prefix . 'invfacil_puntos';
    $t_conteos   = $wpdb->prefix . 'invfacil_conteos';
    $t_items     = $wpdb->prefix . 'invfacil_conteo_items';
    $t_erp       = $wpdb->prefix . 'invfacil_productos_erp';
    $t_bitacora  = $wpdb->prefix . 'invfacil_bitacora';
    $mensaje = '';

    static $admin_procesado = false;

    if ( isset($_POST['invfacil_crear_punto']) && !$admin_procesado ) {
        $admin_procesado = true;
        $nombre_punto = isset($_POST['nombre_punto']) ? sanitize_text_field($_POST['nombre_punto']) : '';
        if ( !empty($nombre_punto) ) {
            $wpdb->insert($t_puntos, array('nombre_punto' => $nombre_punto, 'jefe_id' => intval($_POST['jefe_id'])));
            $mensaje = "<div class='inv-msj-ok'>✅ Punto de atención creado exitosamente.</div>";
        } else {
            $mensaje = "<div class='inv-msj-warn'>⚠️ Error: El nombre del punto está vacío.</div>";
        }
        $mensaje .= "<script>if ( window.history.replaceState ) { window.history.replaceState( null, null, window.location.href ); }</script>";
    }
    
    if ( isset($_GET['del_punto']) && !$admin_procesado ) {
        $admin_procesado = true;
        $wpdb->delete($t_puntos, array('id' => intval($_GET['del_punto'])));
        $mensaje = "<div class='inv-msj-ok'>✅ Punto eliminado.</div>";
        $mensaje .= "<script>if ( window.history.replaceState ) { window.history.replaceState( null, null, window.location.href.split('?')[0] ); }</script>";
    }

    if ( isset($_POST['cargar_maestra']) && isset($_FILES['csv_maestra']) && !$admin_procesado ) {
        $admin_procesado = true;
        $file = $_FILES['csv_maestra']['tmp_name'];
        $cargados = 0;
        $handle = fopen($file, "r");
        if ($handle !== FALSE) {
            $delimiter = ',';
            $first_line = fgets($handle);
            if (strpos($first_line, ';') !== false) { $delimiter = ';'; }
            elseif (strpos($first_line, "\t") !== false) { $delimiter = "\t"; }
            rewind($handle);
            if(isset($_POST['reemplazar_maestra'])) { $wpdb->query("TRUNCATE TABLE $t_erp"); }
            while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
                if ( isset($data[0]) && isset($data[1]) ) {
                    $cod = sanitize_text_field(preg_replace('/\xEF\xBB\xBF/', '', mb_convert_encoding(trim($data[0]), 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252')));
                    $nom = sanitize_text_field(mb_convert_encoding(trim($data[1]), 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252'));
                    if (!empty($cod) && strtolower($cod) !== 'codigo' && strtolower($cod) !== 'código bodega') {
                        $wpdb->query($wpdb->prepare("REPLACE INTO $t_erp (codigo, nombre) VALUES (%s, %s)", $cod, $nom));
                        $cargados++;
                    }
                }
            }
            fclose($handle);
            $mensaje .= "<div class='inv-msj-ok'>✅ Base Maestra Actualizada: $cargados productos en el diccionario.</div>";
            if(function_exists('invfacil_registrar_bitacora')) invfacil_registrar_bitacora("Actualizó la Base Maestra desde el Frontend ($cargados ítems)");
        }
        $mensaje .= "<script>if ( window.history.replaceState ) { window.history.replaceState( null, null, window.location.href ); }</script>";
    }

    if ( isset($_POST['invfacil_asignar']) && !$admin_procesado ) {
        $admin_procesado = true;
        $punto_id = intval($_POST['punto_id']);
        $verificador_id = intval($_POST['verificador_id']);
        $jefe_id = intval($_POST['jefe_id']);
        
        $hace_30_segundos = date('Y-m-d H:i:s', current_time('timestamp') - 30);
        $duplicado = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_conteos WHERE punto_id = %d AND verificador_id = %d AND fecha_asignacion > %s", $punto_id, $verificador_id, $hace_30_segundos));

        if ( $duplicado ) {
            $mensaje = "<div class='inv-msj-warn'>⚠️ Ya creaste una asignación idéntica hace unos segundos. El sistema bloqueó el clon.</div>";
        } else {
            $wpdb->insert($t_conteos, array('punto_id' => $punto_id, 'verificador_id' => $verificador_id, 'jefe_id' => $jefe_id, 'fecha_asignacion' => current_time('mysql'), 'estado' => 'pendiente', 'intento' => 1));
            $conteo_id = $wpdb->insert_id;

            if ( isset($_FILES['csv_productos']) && $_FILES['csv_productos']['size'] > 0 ) {
                $file = $_FILES['csv_productos']['tmp_name'];
                $handle = fopen($file, "r");
                if ($handle !== FALSE) {
                    $delimiter = ',';
                    $first_line = fgets($handle);
                    if (strpos($first_line, ';') !== false) { $delimiter = ';'; }
                    elseif (strpos($first_line, "\t") !== false) { $delimiter = "\t"; }
                    rewind($handle);

                    while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
                        if ( isset($data[0]) && isset($data[1]) ) {
                            $cod = sanitize_text_field(preg_replace('/\xEF\xBB\xBF/', '', mb_convert_encoding(trim($data[0]), 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252')));
                            $nom = sanitize_text_field(mb_convert_encoding(trim($data[1]), 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252'));
                            
                            if (empty($cod) || strtolower($cod) === 'codigo' || strtolower($cod) === 'código bodega' || strtolower($cod) === 'código') continue;
                            
                            $cant_raw = isset($data[2]) ? preg_replace('/[^\d.,-]/', '', trim($data[2])) : '0';
                            $cant_esp = number_format(floatval(str_replace(',', '.', $cant_raw)), 2, '.', '');
                            
                            $unidad_sys = 'Unidad';
                            if (isset($data[3]) && trim($data[3]) !== '') { $unidad_sys = ucfirst(strtolower(sanitize_text_field(trim($data[3])))); }
                            
                            $existe = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_items WHERE conteo_id = %d AND codigo = %s", $conteo_id, $cod));
                            
                            if ( ! $existe ) {
                                $wpdb->insert($t_items, array(
                                    'conteo_id' => $conteo_id, 'codigo' => $cod, 'nombre_producto' => $nom,
                                    'cantidad_esperada' => $cant_esp, 'unidad_sistema' => $unidad_sys,
                                    'cantidad_ingresada'=> 0, 'unidad_conteo' => 'Unidad', 'cantidad' => 0.00 
                                ));
                            }
                        }
                    }
                    fclose($handle);
                }
            }
            $mensaje = "<div class='inv-msj-ok'>✅ Asignación creada enviada al Verificador.</div>";
            $nombre_punto = $wpdb->get_var($wpdb->prepare("SELECT nombre_punto FROM $t_puntos WHERE id = %d", $punto_id));
            if(function_exists('invfacil_registrar_bitacora')) invfacil_registrar_bitacora("Asignó inventario desde Frontend para la sede '$nombre_punto'");
        }
        $mensaje .= "<script>if ( window.history.replaceState ) { window.history.replaceState( null, null, window.location.href ); }</script>";
    }

    if ( isset($_POST['invfacil_rescatar_offline']) && isset($_FILES['csv_respaldo']) && !$admin_procesado ) {
        $admin_procesado = true;
        $conteo_id = intval($_POST['conteo_rescate_id']);
        $file = $_FILES['csv_respaldo']['tmp_name'];
        
        $conteo = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_conteos WHERE id = %d AND estado = 'pendiente'", $conteo_id));
        
        if ( $conteo && $handle = fopen($file, "r") ) {
            $first_line = fgets($handle); 
            $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
            rewind($handle);
            
            $es_paracaidas_js = (strpos($first_line, 'ID_Item') !== false);
            fgetcsv($handle, 10000, $delimiter); 
            
            $intento_csv = 0;
            $filas_a_procesar = array();
            
            while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
                if (count($data) >= 2) {
                    if ($es_paracaidas_js) {
                        if ($intento_csv === 0 && isset($data[7])) $intento_csv = intval(trim($data[7]));
                        $filas_a_procesar[] = array(
                            'id' => intval(preg_replace('/\xEF\xBB\xBF/', '', trim($data[0]))),
                            'codigo' => trim($data[1]),
                            'nombre' => trim($data[2]),
                            'cantidad' => number_format(floatval(str_replace(',', '.', preg_replace('/[^\d.,-]/', '', trim($data[3])))), 2, '.', ''),
                            'unidad' => trim($data[4]),
                            'fecha' => !empty(trim($data[5])) ? trim($data[5]) : null,
                            'obs' => trim($data[6])
                        );
                    } else {
                        $col_cantidad = 5; 
                        $cod_puro = preg_replace('/\xEF\xBB\xBF/', '', trim($data[0]));
                        if(!empty($cod_puro)) {
                            $cant_raw = isset($data[$col_cantidad]) ? preg_replace('/[^\d.,-]/', '', trim($data[$col_cantidad])) : '0';
                            $filas_a_procesar[] = array(
                                'id' => 0, 
                                'codigo' => $cod_puro,
                                'nombre' => isset($data[2]) ? trim($data[2]) : '', 
                                'cantidad' => number_format(floatval(str_replace(',', '.', $cant_raw)), 2, '.', ''),
                                'unidad' => 'Unidad',
                                'fecha' => null,
                                'obs' => ''
                            );
                        }
                    }
                }
            }
            fclose($handle);
            
            if ($intento_csv === 0) $intento_csv = 1; 
            
            if ($es_paracaidas_js && $intento_csv == 1 && $conteo->intento == 2) {
                $mensaje = "<div class='inv-msj-warn'>⚠️ <strong>Operación Denegada:</strong> Este respaldo es del Intento 1, pero el servidor ya había recibido esos datos. El verificador debe completar el Reconteo en su pantalla.</div>";
            }
            else if ($es_paracaidas_js && $intento_csv == 2 && $conteo->intento == 1) {
                $mensaje = "<div class='inv-msj-warn'>⚠️ <strong>Archivo Inválido:</strong> Subiendo un archivo del Intento 2 para un inventario en Intento 1.</div>";
            }
            else {
                foreach ($filas_a_procesar as $d) {
                    if ( $d['id'] > 0 ) {
                        $wpdb->update($t_items, array('cantidad' => $d['cantidad'], 'unidad_conteo' => $d['unidad'], 'fecha_vencimiento' => $d['fecha'], 'observaciones' => $d['obs']), array('id' => $d['id'], 'conteo_id' => $conteo_id));
                    } else if (!empty($d['codigo'])) {
                        $existe_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_items WHERE conteo_id = %d AND codigo = %s", $conteo_id, $d['codigo']));
                        if ($existe_id) {
                            $wpdb->update($t_items, array('cantidad' => $d['cantidad']), array('id' => $existe_id));
                        } else {
                            $wpdb->insert($t_items, array(
                                'conteo_id' => $conteo_id, 'codigo' => $d['codigo'], 'nombre_producto' => $d['nombre'],
                                'cantidad_esperada' => 0.00, 'unidad_sistema' => $d['unidad'],
                                'cantidad_ingresada'=> 0, 'unidad_conteo' => $d['unidad'], 'cantidad' => $d['cantidad']
                            ));
                        }
                    }
                }
                
                $generar_pdf = false;
                if ($conteo->intento == 1) {
                    $diferencias = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_items WHERE conteo_id = %d AND ROUND(cantidad, 2) != ROUND(cantidad_esperada, 2)", $conteo_id));
                    if ($diferencias > 0) {
                        $wpdb->update($t_conteos, array('intento' => 2), array('id' => $conteo_id));
                        $mensaje = "<div class='inv-msj-warn'>✅ Datos procesados. ⚠️ Hay diferencias. El inventario pasó a Reconteo (Intento 2).</div>";
                    } else {
                        $generar_pdf = true;
                    }
                } else {
                    $generar_pdf = true;
                }

                if ($generar_pdf) {
                    $punto  = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_puntos WHERE id = %d", $conteo->punto_id));
                    $productos_todos = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_items WHERE conteo_id = %d", $conteo_id));
                    $jefe = get_userdata($conteo->jefe_id);
                    $verificador = get_userdata($conteo->verificador_id);
                    $nombre_sede = $punto ? $punto->nombre_punto : 'Sede Desconocida';
                    
                    $ruta_fpdf = plugin_dir_path( __DIR__ ) . 'includes/fpdf/fpdf.php';
                    if( file_exists($ruta_fpdf) ) {
                        require_once $ruta_fpdf;
                        $pdf = new FPDF();
                        $pdf->SetMargins(35, 10, 10); 
                        $pdf->AddPage();
                        $pdf->SetFont('Arial', 'B', 16);
                        $pdf->Cell(0, 10, utf8_decode('REPORTE OFICIAL DE INVENTARIO FÍSICO'), 0, 1, 'C');
                        $pdf->Ln(5);
                        $pdf->SetFont('Arial', '', 12);
                        $pdf->Cell(0, 8, utf8_decode('Punto de Atención: ' . $nombre_sede), 0, 1);
                        $pdf->Cell(0, 8, utf8_decode('Verificador (Elaboró): ' . ($verificador ? $verificador->display_name : '')), 0, 1);
                        $pdf->Cell(0, 8, utf8_decode('Jefe de Punto (Revisa): ' . ($jefe ? $jefe->display_name : '')), 0, 1);
                        $pdf->Cell(0, 8, utf8_decode('Fecha de Levantamiento: ' . date('d/m/Y H:i')), 0, 1);
                        $pdf->Ln(10);
                        foreach($productos_todos as $p) {
                            $pdf->SetFont('Arial', 'B', 12);
                            $pdf->Cell(0, 8, utf8_decode("Producto: " . $p->nombre_producto), 0, 1);
                            $pdf->SetFont('Arial', '', 12);
                            $pdf->Cell(0, 8, utf8_decode("Cantidad Final: " . floatval($p->cantidad) . " " . $p->unidad_conteo), 0, 1);
                            if(!empty($p->fecha_vencimiento)) $pdf->Cell(0, 8, utf8_decode("Fecha de Vencimiento: " . $p->fecha_vencimiento), 0, 1);
                            if(!empty($p->observaciones)) $pdf->MultiCell(0, 8, utf8_decode("Observaciones: " . $p->observaciones));
                            $pdf->Ln(4); $pdf->Cell(0, 0, '', 'T'); $pdf->Ln(4);
                        }
                        $pdf_content = $pdf->Output('S'); 
                        $pdf_base64 = base64_encode($pdf_content);

                        if( function_exists('invfacil_enviar_azsign') ) {
                            $respuesta_azsign = invfacil_enviar_azsign($pdf_base64, ($verificador ? $verificador->user_email : ''), ($verificador ? $verificador->display_name : ''), ($jefe ? $jefe->user_email : ''), ($jefe ? $jefe->display_name : ''), $nombre_sede);
                            $acuerdo_id = '';
                            if(preg_match('/AcuerdoId="([^"]+)"/i', $respuesta_azsign['response'], $matches)) { $acuerdo_id = $matches[1]; }
                            $wpdb->update($t_conteos, array('estado' => 'enviado_azsign', 'azsign_acuerdo_id' => $acuerdo_id), array('id' => $conteo_id));
                            if(function_exists('invfacil_registrar_bitacora')) invfacil_registrar_bitacora("Rescate manual finalizó el inventario #$conteo_id (Intento: $conteo->intento)");
                            $mensaje = "<div class='inv-msj-ok'>✅ Datos procesados. Inventario Cuadrado y Enviado a AZSign.</div>";
                        }
                    }
                }
            }
        } else {
            $mensaje = "<div class='inv-msj-warn'>⚠️ Error al leer el archivo o la asignación ya no está pendiente.</div>";
        }
        $mensaje .= "<script>if ( window.history.replaceState ) { window.history.replaceState( null, null, window.location.href ); }</script>";
    }

    if ( isset($_GET['borrar']) && isset($_GET['admin_nonce']) && wp_verify_nonce($_GET['admin_nonce'], 'admin_action') && !$admin_procesado ) {
        $admin_procesado = true;
        $borrar_id = intval($_GET['borrar']);
        $wpdb->delete($t_items, array('conteo_id' => $borrar_id));
        $wpdb->delete($t_conteos, array('id' => $borrar_id));
        $mensaje .= "<div class='inv-msj-ok'>✅ Registro borrado permanentemente.</div>";
        $mensaje .= "<script>if ( window.history.replaceState ) { window.history.replaceState( null, null, window.location.href.split('&')[0] ); }</script>";
    }

    if ( isset($_GET['anular']) && isset($_GET['admin_nonce']) && wp_verify_nonce($_GET['admin_nonce'], 'admin_action') && !$admin_procesado ) {
        $admin_procesado = true;
        $anular_id = intval($_GET['anular']);
        $wpdb->update($t_conteos, array('estado' => 'anulado'), array('id' => $anular_id));
        $mensaje .= "<div class='inv-msj-warn'>⚠️ Asignación anulada.</div>";
        $mensaje .= "<script>if ( window.history.replaceState ) { window.history.replaceState( null, null, window.location.href.split('&')[0] ); }</script>";
    }

    $puntos = $wpdb->get_results("SELECT * FROM $t_puntos ORDER BY nombre_punto ASC");
    $jefes = get_users(array('role' => 'invfacil_jefe'));
    $verificadores = get_users(array('role' => 'invfacil_verificador'));
    $conteos_pendientes = $wpdb->get_results("SELECT * FROM $t_conteos WHERE estado = 'pendiente' ORDER BY fecha_asignacion ASC");
    $historico = $wpdb->get_results("SELECT * FROM $t_conteos ORDER BY fecha_asignacion DESC LIMIT 100");
    $logs = ($modo == 'auditor') ? $wpdb->get_results("SELECT * FROM $t_bitacora ORDER BY fecha_hora DESC LIMIT 100") : [];
    $total_maestra = $wpdb->get_var("SELECT COUNT(*) FROM $t_erp");

    ob_start();
    ?>
    <div class="tabs-container">
        <?php echo $mensaje; ?>
        <div class="tabs-header">
            <button class="tab-btn active" onclick="openInvTab(event, 'tab-inventarios')">📋 Inventarios</button>
            <button class="tab-btn" onclick="openInvTab(event, 'tab-puntos')">📍 Puntos de Atención</button>
            <button class="tab-btn" onclick="openInvTab(event, 'tab-historico')">📂 Histórico</button>
            <?php if($modo == 'auditor'): ?>
                <button class="tab-btn" style="color: #9f1239; margin-left: auto;" onclick="openInvTab(event, 'tab-bitacora')">🕵️ Bitácora</button>
            <?php endif; ?>
        </div>

        <div class="tab-content">
            
            <div id="tab-inventarios" class="inv-panel-tab active">
                
                <div class="front-admin-card" style="border-top: 4px solid #10b981;">
                    <h2>📚 1. Base Maestra Consolidada (Diccionario)</h2>
                    <form method="post" enctype="multipart/form-data" onsubmit="let btn = this.querySelector('.front-btn-green'); btn.innerHTML = '⏳ Cargando...'; btn.style.pointerEvents = 'none'; btn.style.opacity = '0.7';">
                        <div class="front-row">
                            <div class="front-col" style="flex: 2;">
                                <label class="front-label">Subir Archivo CSV General</label>
                                <input type="file" name="csv_maestra" accept=".csv" required class="front-input front-file-input">
                            </div>
                            <div class="front-col" style="flex: 1;">
                                <label class="front-label">Opciones</label>
                                <label class="front-checkbox-wrapper">
                                    <input type="checkbox" name="reemplazar_maestra" value="1">
                                    <span>Reemplazar Lista Actual</span>
                                </label>
                            </div>
                            <div class="front-col" style="flex: 1;">
                                <button type="submit" name="cargar_maestra" class="front-btn front-btn-green">Cargar Base</button>
                            </div>
                        </div>
                    </form>
                    <div class="info-tag">Total de productos en el sistema: <strong style="color:#0f172a;"><?php echo $total_maestra; ?></strong></div>
                </div>

                <div class="front-admin-card" style="border-top: 4px solid #0ea5e9;">
                    <h2>📋 2. Asignar Nuevo Inventario a Sede</h2>
                    <form method="post" enctype="multipart/form-data" onsubmit="let btn = this.querySelector('button[name=\'invfacil_asignar\']'); btn.innerHTML = '⏳ Asignando...'; btn.style.pointerEvents = 'none'; btn.style.opacity = '0.7';">
                        <div class="front-row">
                            <div class="front-col">
                                <label class="front-label">Punto de Atención (Sede)</label>
                                <select name="punto_id" class="front-select" required>
                                    <option value="">-- Seleccionar Sede --</option>
                                    <?php foreach ( $puntos as $p ) echo "<option value='{$p->id}'>{$p->nombre_punto}</option>"; ?>
                                </select>
                            </div>
                            <div class="front-col">
                                <label class="front-label">Archivo Base (CSV de la Sede)</label>
                                <input type="file" name="csv_productos" accept=".csv" class="front-input front-file-input" required>
                            </div>
                        </div>
                        <div class="front-row">
                            <div class="front-col">
                                <label class="front-label">Verificador Asignado</label>
                                <select name="verificador_id" class="front-select" required>
                                    <option value="">-- Seleccionar Verificador --</option>
                                    <?php foreach ( $verificadores as $v ) {
                                        $nombre_v = trim($v->first_name . ' ' . $v->last_name);
                                        $nombre_v = !empty($nombre_v) ? $nombre_v : $v->display_name;
                                        echo "<option value='{$v->ID}'>{$nombre_v}</option>";
                                    } ?>
                                </select>
                            </div>
                            <div class="front-col">
                                <label class="front-label">Jefe de Punto Asignado</label>
                                <select name="jefe_id" class="front-select" required>
                                    <option value="">-- Seleccionar Jefe --</option>
                                    <?php foreach ( $jefes as $j ) {
                                        $nombre_j = trim($j->first_name . ' ' . $j->last_name);
                                        $nombre_j = !empty($nombre_j) ? $nombre_j : $j->display_name;
                                        echo "<option value='{$j->ID}'>{$nombre_j}</option>";
                                    } ?>
                                </select>
                            </div>
                        </div>
                        <div style="display: flex; justify-content: flex-end; margin-top: 10px;">
                            <button type="submit" name="invfacil_asignar" class="front-btn" style="width: auto; padding: 0 40px;">Generar Asignación</button>
                        </div>
                    </form>
                </div>
                
                <div class="front-admin-card" style="border-top: 4px solid #f43f5e; background: #fff1f2;">
                    <h2>🆘 3. Cargar Respaldo Offline (Puente de Verificador)</h2>
                    <p style="font-size: 14px; color: #881337; margin-top:-15px; margin-bottom: 20px;">Use esto solo si el verificador descargó el archivo en la bodega al quedarse sin internet.</p>
                    <form method="post" enctype="multipart/form-data" onsubmit="let btn = this.querySelector('button[name=\'invfacil_rescatar_offline\']'); btn.innerHTML = '⏳ Procesando Respaldo...'; btn.style.pointerEvents = 'none'; btn.style.opacity = '0.7';">
                        <div class="front-row">
                            <div class="front-col">
                                <label class="front-label">Inventario Atascado (Pendiente)</label>
                                <select name="conteo_rescate_id" class="front-select" required style="border-color: #fca5a5;">
                                    <option value="">-- Seleccione el inventario --</option>
                                    <?php foreach ( $conteos_pendientes as $cp ) {
                                        $p_nombre = $wpdb->get_var($wpdb->prepare("SELECT nombre_punto FROM $t_puntos WHERE id = %d", $cp->punto_id));
                                        echo "<option value='{$cp->id}'>ID #{$cp->id} - {$p_nombre} (Intento {$cp->intento})</option>";
                                    } ?>
                                </select>
                            </div>
                            <div class="front-col">
                                <label class="front-label">Subir Archivo de Respaldo (.csv)</label>
                                <input type="file" name="csv_respaldo" accept=".csv" class="front-input front-file-input" required style="border-color: #fca5a5; background: #fff;">
                            </div>
                        </div>
                        <div style="display: flex; justify-content: flex-end;">
                            <button type="submit" name="invfacil_rescatar_offline" class="front-btn front-btn-red" style="width: auto; padding: 0 40px;">Subir y Verificar Datos</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="tab-puntos" class="inv-panel-tab">
                <div class="front-admin-card" style="border-top: 4px solid #f59e0b;">
                    <h2>📍 Crear Nueva Sede / Punto de Atención</h2>
                    <form method="post" onsubmit="let btn = this.querySelector('.front-btn-amber'); btn.innerHTML = '⏳ Agregando...'; btn.style.pointerEvents = 'none'; btn.style.opacity = '0.7';">
                        <div class="front-row">
                            <div class="front-col" style="flex: 2;">
                                <label class="front-label">Nombre de la Sede</label>
                                <input type="text" name="nombre_punto" placeholder="Ej: Sede Norte Principal" required class="front-input">
                            </div>
                            <div class="front-col" style="flex: 2;">
                                <label class="front-label">Jefe Responsable</label>
                                <select name="jefe_id" required class="front-select">
                                    <option value="">-- Asignar Jefe --</option>
                                    <?php foreach($jefes as $j) {
                                        $nombre_j = trim($j->first_name . ' ' . $j->last_name);
                                        $nombre_j = !empty($nombre_j) ? $nombre_j : $j->display_name;
                                        echo "<option value='{$j->ID}'>{$nombre_j}</option>";
                                    } ?>
                                </select>
                            </div>
                            <div class="front-col" style="flex: 1;">
                                <button type="submit" name="invfacil_crear_punto" class="front-btn front-btn-amber">Agregar Sede</button>
                            </div>
                        </div>
                    </form>
                </div>

                <h3 style="color: #0f172a; margin-top: 40px; margin-bottom: 15px;">Sedes Registradas</h3>
                <table class="front-table">
                    <thead><tr><th style="width:80px;">ID</th><th>Nombre del Punto</th><th>Jefe Responsable</th><th style="text-align:right;">Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach($puntos as $p): 
                            $j_info = get_userdata($p->jefe_id);
                            
                            $nombre_j_tabla = '<span style="color:#ef4444;">No asignado</span>';
                            if ($j_info) {
                                $nombre_j_tabla = trim($j_info->first_name . ' ' . $j_info->last_name);
                                $nombre_j_tabla = !empty($nombre_j_tabla) ? $nombre_j_tabla : $j_info->display_name;
                            }
                        ?>
                        <tr>
                            <td style="color:#64748b; font-weight:600;">#<?php echo $p->id; ?></td>
                            <td><strong><?php echo $p->nombre_punto; ?></strong></td>
                            <td><?php echo $nombre_j_tabla; ?></td>
                            <td style="text-align:right;"><a href="?del_punto=<?php echo $p->id; ?>&admin_nonce=<?php echo wp_create_nonce('admin_action'); ?>" class="front-action-link" onclick="return confirm('¿Eliminar sede definitivamente?')">Eliminar</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($puntos)): ?>
                            <tr><td colspan="4" style="text-align:center; color:#64748b; padding:30px;">No hay sedes creadas.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="tab-historico" class="inv-panel-tab">
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                    <h2 style="margin:0; color:#0f172a;">📂 Histórico de Inventarios (Últimos 100)</h2>
                    
                    <div style="display: flex; gap: 15px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #cbd5e1;">
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: bold; color: #475569; margin-bottom: 5px;">Sede:</label>
                            <select id="filtro-sede-hist" onchange="filtrarHistorico()" style="padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1; width: 180px;">
                                <option value="">Todas las Sedes</option>
                                <?php foreach($puntos as $p) echo "<option value='" . esc_attr($p->nombre_punto) . "'>" . esc_html($p->nombre_punto) . "</option>"; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: bold; color: #475569; margin-bottom: 5px;">Fecha:</label>
                            <input type="date" id="filtro-fecha-hist" onchange="filtrarHistorico()" style="padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        </div>
                        <div style="align-self: flex-end;">
                            <button type="button" onclick="document.getElementById('filtro-sede-hist').value=''; document.getElementById('filtro-fecha-hist').value=''; filtrarHistorico();" style="padding: 9px 15px; background: #64748b; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">Limpiar</button>
                        </div>
                    </div>
                </div>
                
                <table class="front-table">
                    <thead><tr><th>Fecha / Hora (Local)</th><th>Sede</th><th>Verificador</th><th>Estado</th><th>Documentos / Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach($historico as $h): 
                            $pto = $wpdb->get_row($wpdb->prepare("SELECT nombre_punto FROM $t_puntos WHERE id = %d", $h->punto_id));
                            $v_user = get_userdata(intval($h->verificador_id));
                            
                            $estado_html = '';
                            if($h->estado == 'pendiente') $estado_html = "<span class='front-badge badge-pend'>En Progreso (Intento $h->intento)</span>";
                            elseif($h->estado == 'enviado_azsign') $estado_html = "<span class='front-badge badge-firm'>Enviado y Firmado</span>";
                            elseif($h->estado == 'anulado') $estado_html = "<span class='front-badge badge-anul'>Anulado</span>";
                            
                            $fecha_pura = date('Y-m-d', strtotime($h->fecha_asignacion));
                            $nombre_sede_pura = $pto ? $pto->nombre_punto : '';
                        ?>
                        <tr class="fila-historico" data-sede="<?php echo esc_attr($nombre_sede_pura); ?>" data-fecha="<?php echo esc_attr($fecha_pura); ?>">
                            <td class="fecha-pc-local" data-fechaserver="<?php echo esc_attr($h->fecha_asignacion); ?>" style="color:#475569; font-weight:500;">
                                ⏳ Cargando hora...
                            </td>
                            <td><strong><?php echo $pto ? $pto->nombre_punto : 'Sede Eliminada'; ?></strong></td>
                            <td><?php echo $v_user ? $v_user->display_name : 'Usuario Borrado'; ?></td>
                            <td><?php echo $estado_html; ?></td>
                            <td>
                                <?php if($h->estado == 'enviado_azsign'): ?>
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <a href="<?php echo esc_url(add_query_arg('descargar_pdf_azsign', $h->id, site_url())); ?>" target="_blank" style="background:#e0f2fe; color:#0284c7; padding:6px 12px; border-radius:6px; text-decoration:none; font-weight:600; font-size:12px; display:inline-flex; align-items:center; gap:5px; border:1px solid #bae6fd; transition:0.2s;" onmouseover="this.style.background='#bae6fd'" onmouseout="this.style.background='#e0f2fe'">📄 PDF</a>
                                        <a href="<?php echo esc_url(site_url('?exportar_csv_erp=' . $h->id)); ?>" style="background:#d1fae5; color:#059669; padding:6px 12px; border-radius:6px; text-decoration:none; font-weight:600; font-size:12px; display:inline-flex; align-items:center; gap:5px; border:1px solid #a7f3d0; transition:0.2s;" onmouseover="this.style.background='#a7f3d0'" onmouseout="this.style.background='#d1fae5'">📊 CSV (ERP)</a>
                                    </div>
                                    <?php if(!empty($h->azsign_acuerdo_id)): ?>
                                        <div style="margin-top: 8px; font-size: 11px; color: #64748b; font-family: monospace; background: #f8fafc; padding: 4px 8px; border-radius: 4px; display: inline-block; border: 1px solid #e2e8f0;">
                                            ID AZSign: <strong style="color: #334155;"><?php echo esc_html($h->azsign_acuerdo_id); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:13px; font-weight:500;">No disponibles aún</span>
                                <?php endif; ?>
                                
                                <?php if($h->estado == 'pendiente'): ?>
                                    <br><a href="?anular=<?php echo $h->id; ?>&admin_nonce=<?php echo wp_create_nonce('admin_action'); ?>" class="front-action-warn" style="display:inline-block; margin-top:8px;" onclick="return confirm('¿Anular esta asignación?');">⚠️ Anular</a>
                                <?php endif; ?>
                                <?php if($h->estado != 'enviado_azsign'): ?>
                                    <br><a href="?borrar=<?php echo $h->id; ?>&admin_nonce=<?php echo wp_create_nonce('admin_action'); ?>" class="front-action-link" style="display:inline-block; margin-top:8px;" onclick="return confirm('¿Borrar definitivamente todo este registro y sus productos de la base de datos?');">Borrar Todo</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($historico)): ?>
                            <tr><td colspan="5" style="text-align:center; color:#64748b; padding:30px;">No hay inventarios registrados en el sistema.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if($modo == 'auditor'): ?>
            <div id="tab-bitacora" class="inv-panel-tab">
                <h2 style="color: #9f1239; margin-top:0; border-bottom: 2px solid #ffe4e6; padding-bottom:15px; margin-bottom: 20px;">🕵️ Bitácora de Auditoría de Seguridad</h2>
                <table class="front-table" style="font-size: 13px;">
                    <thead><tr style="background:#fff1f2;"><th>Fecha / Hora</th><th>Usuario y Rol</th><th>Dirección IP</th><th>Acción Registrada</th></tr></thead>
                    <tbody>
                        <?php foreach($logs as $l): ?>
                        <tr>
                            <td style="color:#475569; width: 140px;"><?php echo date('d/m/Y H:i:s', strtotime($l->fecha_hora)); ?></td>
                            <td style="width: 180px;"><strong><?php echo $l->usuario; ?></strong><br><span style="color:#64748b; font-size:11px; text-transform:uppercase;"><?php echo str_replace('invfacil_', '', $l->rol); ?></span></td>
                            <td style="width: 120px;"><code style="background:#f1f5f9; padding:4px 8px; border-radius:4px; color:#334155;"><?php echo $l->ip; ?></code></td>
                            <td style="color:#1e293b;"><?php echo $l->accion; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($logs)): ?>
                            <tr><td colspan="4" style="text-align:center; color:#64748b; padding:30px;">La bitácora está vacía.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function openInvTab(evt, tabName) {
        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("inv-panel-tab");
        for (i = 0; i < tabcontent.length; i++) { tabcontent[i].classList.remove("active"); }
        tablinks = document.getElementsByClassName("tab-btn");
        for (i = 0; i < tablinks.length; i++) { tablinks[i].classList.remove("active"); }
        document.getElementById(tabName).classList.add("active");
        evt.currentTarget.classList.add("active");
    }
    </script>
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
    jQuery(document).ready(function($) {
        $('select[name="verificador_id"]').select2({ placeholder: "-- Buscar Verificador --", width: '100%' });
        $('select[name="jefe_id"]').select2({ placeholder: "-- Buscar Jefe --", width: '100%' });
        $('#filtro-sede-hist').select2({ placeholder: "-- Buscar Sede --", width: '100%' });
    });

    function filtrarHistorico() {
        let sede = document.getElementById('filtro-sede-hist').value.toLowerCase();
        let fecha = document.getElementById('filtro-fecha-hist').value;

        document.querySelectorAll('.fila-historico').forEach(fila => {
            let sedeFila = fila.getAttribute('data-sede').toLowerCase();
            let fechaFila = fila.getAttribute('data-fecha');

            let matchSede = (sede === "" || sedeFila.includes(sede));
            let matchFecha = (fecha === "" || fechaFila === fecha);

            if (matchSede && matchFecha) {
                fila.style.display = "";
            } else {
                fila.style.display = "none";
            }
        });
    }

    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('.fecha-pc-local').forEach(el => {
            let serverDateStr = el.getAttribute('data-fechaserver');
            let d = new Date(serverDateStr.replace(' ', 'T'));
            if (!isNaN(d)) {
                el.innerText = d.toLocaleString(undefined, { 
                    day: '2-digit', month: '2-digit', year: 'numeric', 
                    hour: '2-digit', minute: '2-digit' 
                });
            } else {
                el.innerText = serverDateStr; 
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
// ========================================================================
// SHORTCODE 4 ACTUALIZADO: RECEPCIÓN CON DOBLE PAD OBLIGATORIO
// ========================================================================
add_shortcode( 'recepcion_pedidos', 'invfacil_shortcode_recepcion_pedidos' );

function invfacil_shortcode_recepcion_pedidos() {
    if ( ! is_user_logged_in() ) return '<div class="inv-msj-warn">Debe iniciar sesión.</div>';
    
    global $wpdb;
    $usuario_actual = wp_get_current_user();
    $t_rec = $wpdb->prefix . 'invfacil_recepciones';
    $t_rec_it = $wpdb->prefix . 'invfacil_recepcion_items';
    $mensaje = '';

    if (isset($_POST['guardar_recepcion_final'])) {
        $nume_erp = sanitize_text_field($_POST['nume_erp']);
        $entregador_id = intval($_POST['entregador_id']);
        $nombre_recibe = sanitize_text_field($_POST['nombre_recibe']);
        $firma_e = $_POST['firma_entrega_b64'];
        $firma_v = $_POST['firma_verifica_b64'];
        
        if(empty($firma_e) || strlen($firma_e) < 1000 || empty($firma_v) || strlen($firma_v) < 1000 || empty($nombre_recibe)) {
            $mensaje = '<div class="inv-msj-warn">⚠️ Ambas firmas y el nombre de quien recibe son obligatorios. Asegúrese de completar todos los campos.</div>';
        } else {
            $wpdb->insert($t_rec, array(
                'nume_erp' => $nume_erp,
                'bodega_origen' => sanitize_text_field($_POST['bodega_origen']),
                'bodega_destino' => sanitize_text_field($_POST['bodega_destino']),
                'fecha_recepcion' => current_time('mysql'),
                'receptor_id' => $usuario_actual->ID,
                'entregador_id' => $entregador_id,
                'nombre_recibe' => $nombre_recibe,
                'firma_entrega' => $firma_e,
                'firma_verifica' => $firma_v,
                'estado_erp' => 'pendiente_erp'
            ));
            $recepcion_id = $wpdb->insert_id;

            if (isset($_POST['items']) && is_array($_POST['items'])) {
                foreach ($_POST['items'] as $item) {
                    $entregada = floatval(str_replace(',', '.', $item['entregada']));
                    if ($entregada > 0) {
                        $wpdb->insert($t_rec_it, array(
                            'recepcion_id' => $recepcion_id,
                            'codigo' => sanitize_text_field($item['codigo']),
                            'nombre' => sanitize_text_field($item['nombre']),
                            'unidad' => sanitize_text_field($item['unidad']),
                            'cant_entregada' => $entregada
                        ));
                    }
                }
            }
            $url_pdf = esc_url(add_query_arg('descargar_pdf_recepcion', $recepcion_id, site_url()));
            return "<div class='inv-facil-form'><div class='inv-msj-ok'>✅ Recepción guardada y validada con doble firma correctamente.</div><br><a href='$url_pdf' target='_blank' class='front-btn front-btn-green'>📄 Descargar Acta en PDF</a></div>";
        }
    }

    $usuarios = get_users(); // Trae a todos sin importar el rol (Bug 2)

    ob_start();
    ?>
    <div class="inv-facil-form">
        <h2>📥 Recepción de Traslado (Validación)</h2>
        <?php echo $mensaje; ?>

        <?php if (!isset($_POST['procesar_xml']) && !isset($_POST['guardar_recepcion_final'])): ?>
            <div class="inv-seccion">
                <h3>1. Cargar Documento e Identificar Entrega</h3>
                <p>El administrador sube el XML y selecciona quién hace la entrega física.</p>
                <form method="post" enctype="multipart/form-data">
                    <label class="front-label">Funcionario que Entrega:</label>
                    <select name="entregador_id" required class="front-select" style="margin-bottom: 15px;">
                        <option value="">-- Seleccionar Funcionario --</option>
                        <?php foreach($usuarios as $u) {
                            $n = trim($u->first_name . ' ' . $u->last_name);
                            $n = !empty($n) ? $n : $u->display_name;
                            echo "<option value='{$u->ID}'>{$n}</option>";
                        } ?>
                    </select>

                    <label class="front-label">Archivo XML (Descargado del ERP):</label>
                    <input type="file" name="xml_pedido" accept=".xml" required class="front-input front-file-input" style="background:#fff;">
                    
                    <button type="submit" name="procesar_xml" class="front-btn front-btn-amber" style="margin-top:20px;">🔍 Leer Documento y Continuar</button>
                </form>
            </div>
        <?php 
        elseif (isset($_POST['procesar_xml']) && !empty($_FILES['xml_pedido']['tmp_name'])): 
            $xml_content = file_get_contents($_FILES['xml_pedido']['tmp_name']);
            
            preg_match('/<Field Name="SOLNUSO1"[^>]*>.*?<Value>([^<]+)<\/Value>/s', $xml_content, $m_nume);
            $nume_erp = isset($m_nume[1]) ? intval($m_nume[1]) : 0;
            
            preg_match('/<Field Name="BODNOMB1"[^>]*>.*?<Value>([^<]+)<\/Value>/s', $xml_content, $m_ori);
            $bodega_origen = isset($m_ori[1]) ? sanitize_text_field($m_ori[1]) : 'Desconocida';
            
            preg_match('/<Field Name="BODNOMB2"[^>]*>.*?<Value>([^<]+)<\/Value>/s', $xml_content, $m_des);
            $bodega_destino = isset($m_des[1]) ? sanitize_text_field($m_des[1]) : 'Desconocida';

            if ($nume_erp == 0) {
                echo "<div class='inv-msj-warn'>No se detectó el 'Número' en el XML.</div>";
            } else {
                preg_match_all('/<Details Level="2">(.*?)<\/Details>/s', $xml_content, $matches_details);
                $productos_xml = [];
                foreach($matches_details[1] as $detail) {
                    preg_match('/<Field Name="PROCODI1"[^>]*>.*?<Value>([^<]+)<\/Value>/s', $detail, $m_cod);
                    preg_match('/<Field Name="PRONOMB1"[^>]*>.*?<Value>([^<]+)<\/Value>/s', $detail, $m_nom);
                    preg_match('/<Field Name="UNIINIC1"[^>]*>.*?<Value>([^<]+)<\/Value>/s', $detail, $m_uni);
                    preg_match('/<Field Name="DSOCASO1"[^>]*>.*?<Value>([^<]+)<\/Value>/s', $detail, $m_cant);

                    if(!empty($m_cod[1])) {
                        $productos_xml[] = [
                            'codigo' => trim($m_cod[1]),
                            'nombre' => trim($m_nom[1]),
                            'unidad' => trim($m_uni[1]),
                            'solicitada' => floatval(trim($m_cant[1]))
                        ];
                    }
                }
                ?>
                <form method="post" id="formRecepcion" onsubmit="return validarAmbasFirmas(event);">
                    <input type="hidden" name="nume_erp" value="<?php echo esc_attr($nume_erp); ?>">
                    <input type="hidden" name="bodega_origen" value="<?php echo esc_attr($bodega_origen); ?>">
                    <input type="hidden" name="bodega_destino" value="<?php echo esc_attr($bodega_destino); ?>">
                    <input type="hidden" name="entregador_id" value="<?php echo esc_attr($_POST['entregador_id']); ?>">
                    <input type="hidden" name="firma_entrega_b64" id="firma_entrega_b64">
                    <input type="hidden" name="firma_verifica_b64" id="firma_verifica_b64">

                    <div style="background:#e0f2fe; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #bae6fd;">
                        <h3 style="margin-top:0; color:#0369a1;">Recepción Traslado ERP: <?php echo $nume_erp; ?></h3>
                        <p style="margin:0;"><strong>De:</strong> <?php echo $bodega_origen; ?><br><strong>Para:</strong> <?php echo $bodega_destino; ?></p>
                    </div>

                    <table class="front-table" style="margin-bottom: 30px;">
                        <thead><tr><th>Producto</th><th>Pendiente</th><th>Cant. Real Recibida</th></tr></thead>
                        <tbody>
                        <?php 
                        $hay_pendientes = false;
                        $index = 0;
                        foreach ($productos_xml as $px): 
                            $entregado_antes = $wpdb->get_var($wpdb->prepare(
                                "SELECT SUM(cant_entregada) FROM $t_rec_it it INNER JOIN $t_rec r ON it.recepcion_id = r.id WHERE r.nume_erp = %s AND it.codigo = %s",
                                $nume_erp, $px['codigo']
                            ));
                            $pendiente = $px['solicitada'] - floatval($entregado_antes);
                            
                            if ($pendiente > 0): 
                                $hay_pendientes = true;
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($px['nombre']); ?></strong><br>
                                    <small style="color:#64748b;"><?php echo esc_html($px['codigo']); ?> | <?php echo esc_html($px['unidad']); ?></small>
                                    <input type="hidden" name="items[<?php echo $index; ?>][codigo]" value="<?php echo esc_attr($px['codigo']); ?>">
                                    <input type="hidden" name="items[<?php echo $index; ?>][nombre]" value="<?php echo esc_attr($px['nombre']); ?>">
                                    <input type="hidden" name="items[<?php echo $index; ?>][unidad]" value="<?php echo esc_attr($px['unidad']); ?>">
                                </td>
                                <td style="color:#d63638; font-weight:bold; font-size:16px; text-align:center;"><?php echo $pendiente; ?></td>
                                <td>
                                    <input type="number" step="0.01" min="0" max="<?php echo $pendiente; ?>" name="items[<?php echo $index; ?>][entregada]" class="front-input" value="<?php echo $pendiente; ?>" required>
                                </td>
                            </tr>
                        <?php 
                            $index++;
                            endif; 
                        endforeach; 
                        ?>
                        </tbody>
                    </table>

                    <?php if(!$hay_pendientes): ?>
                        <div class="inv-msj-ok">✅ El total de este traslado ya fue recibido en actas anteriores. No hay saldos pendientes.</div>
                        <a href="" class="front-btn">Volver</a>
                    <?php else: ?>
                        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 25px;">
                            <div class="inv-seccion" style="flex: 1; min-width: 280px; text-align: center; border:2px solid #cbd5e1; background:#f8fafc; border-radius:8px;">
                                <h3 style="color:#1e293b; margin-top:0;">✍️ Firma: Quien Entrega</h3>
                                <canvas id="padEntrega" width="400" height="150" style="border: 2px dashed #94a3b8; background: #fff; border-radius: 8px; cursor: crosshair; touch-action: none; max-width:100%;"></canvas>
                                <br><button type="button" onclick="clearPadE()" style="margin-top:10px; padding:6px 15px; background:#cbd5e1; border:none; border-radius:4px; cursor:pointer;">🧹 Limpiar</button>
                            </div>

                            <div class="inv-seccion" style="flex: 1; min-width: 280px; text-align: center; border:2px solid #cbd5e1; background:#f8fafc; border-radius:8px;">
                                <h3 style="color:#1e293b; margin-top:0;">✍️ Firma: Quien Recibe</h3>
                                <label style="display:block; text-align:left; font-size:14px; font-weight:bold; margin-bottom:5px;">Nombre de quien recibe:</label>
                                <input type="text" name="nombre_recibe" required class="front-input" placeholder="Nombre completo" style="margin-bottom: 10px;">
                                
                                <canvas id="padVerifica" width="400" height="150" style="border: 2px dashed #94a3b8; background: #fff; border-radius: 8px; cursor: crosshair; touch-action: none; max-width:100%;"></canvas>
                                <br><button type="button" onclick="clearPadV()" style="margin-top:10px; padding:6px 15px; background:#cbd5e1; border:none; border-radius:4px; cursor:pointer;">🧹 Limpiar</button>
                            </div>
                        </div>
                        <button type="submit" name="guardar_recepcion_final" class="front-btn front-btn-green" style="font-size:18px;">✅ Validar Doble Firma y Guardar</button>
                    <?php endif; ?>
                </form>

                <script>
                    const canvasE = document.getElementById('padEntrega');
                    let drawnPixelsE = 0;
                    if (canvasE) {
                        const ctxE = canvasE.getContext('2d');
                        let drawingE = false;
                        function getPosE(e) { const r = canvasE.getBoundingClientRect(); const cx = e.clientX || (e.touches && e.touches[0].clientX); const cy = e.clientY || (e.touches && e.touches[0].clientY); return { x: cx - r.left, y: cy - r.top }; }
                        canvasE.addEventListener('mousedown', (e) => { drawingE = true; drawE(e); });
                        canvasE.addEventListener('mouseup', () => { drawingE = false; ctxE.beginPath(); });
                        canvasE.addEventListener('mousemove', drawE);
                        canvasE.addEventListener('touchstart', (e) => { drawingE = true; drawE(e); }, {passive: false});
                        canvasE.addEventListener('touchend', () => { drawingE = false; ctxE.beginPath(); });
                        canvasE.addEventListener('touchmove', drawE, {passive: false});

                        function drawE(e) {
                            if (!drawingE) return; e.preventDefault(); const p = getPosE(e);
                            ctxE.lineWidth = 3; ctxE.lineCap = 'round'; ctxE.strokeStyle = '#1e293b';
                            ctxE.lineTo(p.x, p.y); ctxE.stroke(); ctxE.beginPath(); ctxE.moveTo(p.x, p.y);
                            drawnPixelsE++;
                        }
                        window.clearPadE = function() { ctxE.clearRect(0, 0, canvasE.width, canvasE.height); drawnPixelsE = 0; }
                    }

                    const canvasV = document.getElementById('padVerifica');
                    let drawnPixelsV = 0;
                    if (canvasV) {
                        const ctxV = canvasV.getContext('2d');
                        let drawingV = false;
                        function getPosV(e) { const r = canvasV.getBoundingClientRect(); const cx = e.clientX || (e.touches && e.touches[0].clientX); const cy = e.clientY || (e.touches && e.touches[0].clientY); return { x: cx - r.left, y: cy - r.top }; }
                        canvasV.addEventListener('mousedown', (e) => { drawingV = true; drawV(e); });
                        canvasV.addEventListener('mouseup', () => { drawingV = false; ctxV.beginPath(); });
                        canvasV.addEventListener('mousemove', drawV);
                        canvasV.addEventListener('touchstart', (e) => { drawingV = true; drawV(e); }, {passive: false});
                        canvasV.addEventListener('touchmove', drawV, {passive: false});

                        function drawV(e) {
                            if (!drawingV) return; e.preventDefault(); const p = getPosV(e);
                            ctxV.lineWidth = 3; ctxV.lineCap = 'round'; ctxV.strokeStyle = '#1e293b';
                            ctxV.lineTo(p.x, p.y); ctxV.stroke(); ctxV.beginPath(); ctxV.moveTo(p.x, p.y);
                            drawnPixelsV++;
                        }
                        window.clearPadV = function() { ctxV.clearRect(0, 0, canvasV.width, canvasV.height); drawnPixelsV = 0; }
                    }

                    // Validación de firmas obligatorias
                    window.validarAmbasFirmas = function(e) {
                        if (drawnPixelsE < 35 || drawnPixelsV < 35) {
                            e.preventDefault();
                            alert("⚠️ OPERACIÓN DENEGADA: Ambas firmas son obligatorias. Tanto la persona que entrega como la que recibe deben firmar en su recuadro.");
                            return false;
                        }
                        document.getElementById('firma_entrega_b64').value = canvasE.toDataURL('image/png');
                        document.getElementById('firma_verifica_b64').value = canvasV.toDataURL('image/png');
                        return true;
                    }
                </script>
                <?php
            }
        endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
