<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function invfacil_pantalla_admin() {
    global $wpdb;
    $tabla_conteos  = $wpdb->prefix . 'invfacil_conteos';
    $tabla_items    = $wpdb->prefix . 'invfacil_conteo_items';
    $tabla_puntos   = $wpdb->prefix . 'invfacil_puntos';
    $tabla_prod_erp = $wpdb->prefix . 'invfacil_productos_erp'; 
    $mensaje = '';

    // ========================================================================
    // A. CARGA DE BASE MAESTRA GLOBAL (DETECCIÓN INTELIGENTE)
    // ========================================================================
    if ( isset($_POST['cargar_maestra']) && isset($_FILES['csv_maestra']) ) {
        $file = $_FILES['csv_maestra']['tmp_name'];
        $cargados = 0;
        
        $handle = fopen($file, "r");
        if ($handle !== FALSE) {
            // Detectar separador (Coma, Punto y Coma, o Tabulador)
            $delimiter = ',';
            $first_line = fgets($handle);
            if (strpos($first_line, ';') !== false) { $delimiter = ';'; }
            elseif (strpos($first_line, "\t") !== false) { $delimiter = "\t"; }
            rewind($handle);
            
            if(isset($_POST['reemplazar_maestra'])) { $wpdb->query("TRUNCATE TABLE $tabla_prod_erp"); }
            
            while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
                if ( isset($data[0]) && isset($data[1]) ) {
                    // Limpieza de caracteres y codificación
                    $cod = sanitize_text_field(preg_replace('/\xEF\xBB\xBF/', '', mb_convert_encoding(trim($data[0]), 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252')));
                    $nom = sanitize_text_field(mb_convert_encoding(trim($data[1]), 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252'));
                    
                    if (!empty($nom) && strtolower($cod) !== 'codigo' && strtolower($cod) !== 'código bodega') {
                        $wpdb->query($wpdb->prepare("REPLACE INTO $tabla_prod_erp (codigo, nombre) VALUES (%s, %s)", $cod, $nom));
                        $cargados++;
                    }
                }
            }
            fclose($handle);
            $mensaje .= "<div class='notice notice-success is-dismissible'><p><strong>Base Maestra Actualizada:</strong> $cargados productos.</p></div>";
            if(function_exists('invfacil_registrar_bitacora')) invfacil_registrar_bitacora("Actualizó la Base Maestra de Productos ($cargados ítems)");
        }
    }

    // ========================================================================
    // B. ASIGNAR INVENTARIO (DETECCIÓN INTELIGENTE + BLINDAJE MYSQL)
    // ========================================================================
    if ( isset($_POST['invfacil_asignar']) ) {
        $punto_id = intval($_POST['punto_id']);
        $verificador_id = intval($_POST['verificador_id']);
        $jefe_id = intval($_POST['jefe_id']);
        
        // Blindaje MySQL: Declarar la fecha exacta y mostrar errores si falla
        $wpdb->show_errors();
        $wpdb->insert($tabla_conteos, array(
            'punto_id'         => $punto_id, 
            'verificador_id'   => $verificador_id, 
            'jefe_id'          => $jefe_id, 
            'fecha_asignacion' => current_time('mysql'), 
            'estado'           => 'pendiente', 
            'intento'          => 1
        ));
        $conteo_id = $wpdb->insert_id;

        if ( isset($_FILES['csv_productos']) && $_FILES['csv_productos']['size'] > 0 && $conteo_id > 0 ) {
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
                        
                        if (empty($nom) || strtolower($cod) === 'codigo' || strtolower($cod) === 'código bodega') continue;

                        $cant_raw = isset($data[2]) ? str_replace(',', '.', trim($data[2])) : '0';
                        $cant_esp = floatval($cant_raw);
                        
                        $unidad_sys = 'Unidad';
                        if (isset($data[3]) && trim($data[3]) !== '') {
                            $unidad_sys = ucfirst(strtolower(sanitize_text_field(trim($data[3]))));
                        }
                        
                        // Blindaje MySQL: Incluir 'cantidad_ingresada' para evitar rechazos
                        $wpdb->insert($tabla_items, array(
                            'conteo_id'         => $conteo_id,
                            'codigo'            => $cod,
                            'nombre_producto'   => $nom,
                            'cantidad_esperada' => $cant_esp,
                            'unidad_sistema'    => $unidad_sys,
                            'cantidad_ingresada'=> 0, 
                            'unidad_conteo'     => 'Unidad',
                            'cantidad'          => 0 
                        ));
                    }
                }
                fclose($handle);
            }
        }
        
        $mensaje .= '<div class="notice notice-success is-dismissible"><p>Asignación creada y enviada al Verificador.</p></div>';
        $nombre_punto = $wpdb->get_var($wpdb->prepare("SELECT nombre_punto FROM $tabla_puntos WHERE id = %d", $punto_id));
        $v_user = get_userdata($verificador_id);
        if(function_exists('invfacil_registrar_bitacora')) invfacil_registrar_bitacora("Asignó inventario al verificador '".($v_user ? $v_user->display_name : '')."' para la sede '$nombre_punto'");
    }

    // ========================================================================
    // C. ACTUALIZACIÓN, BORRADO Y ANULACIÓN
    // ========================================================================
    if ( isset($_POST['invfacil_actualizar']) ) {
        $conteo_id_edit = intval($_POST['conteo_id']);
        $wpdb->update($tabla_conteos, array(
            'verificador_id' => intval($_POST['verificador_id']), 
            'jefe_id' => intval($_POST['jefe_id']), 
            'azsign_acuerdo_id' => sanitize_text_field($_POST['azsign_acuerdo_id'])
        ), array( 'id' => $conteo_id_edit ));
        $mensaje .= '<div class="notice notice-success is-dismissible"><p>Asignación actualizada.</p></div>';
    }

    if ( isset($_GET['borrar']) ) {
        $borrar_id = intval($_GET['borrar']);
        $wpdb->delete($tabla_items, array('conteo_id' => $borrar_id));
        $wpdb->delete($tabla_conteos, array('id' => $borrar_id));
        $mensaje .= '<div class="notice notice-success is-dismissible"><p>Registro borrado.</p></div>';
    }

    if ( isset($_GET['anular']) ) {
        $anular_id = intval($_GET['anular']);
        $wpdb->update($tabla_conteos, array('estado' => 'anulado'), array('id' => $anular_id));
        $mensaje .= '<div class="notice notice-success is-dismissible"><p>Asignación anulada.</p></div>';
    }

    // Consultas para la Vista
    $puntos = $wpdb->get_results("SELECT * FROM $tabla_puntos ORDER BY nombre_punto ASC");
    $verificadores = get_users(array());
    $jefes = get_users(array('role' => 'invfacil_jefe'));
    $conteos = $wpdb->get_results("SELECT * FROM $tabla_conteos ORDER BY fecha_asignacion DESC");
    $total_maestra = $wpdb->get_var("SELECT COUNT(*) FROM $tabla_prod_erp");
    
    ?>
    <style>
        .inv-wrap { max-width: 1000px; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .inv-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; padding: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); margin-bottom: 30px; }
        .inv-card h2 { margin-top: 0; font-size: 20px; border-bottom: 2px solid #f0f0f1; padding-bottom: 15px; margin-bottom: 20px; }
        .inv-row { display: flex; gap: 20px; margin-bottom: 15px; flex-wrap: wrap;}
        .inv-col { flex: 1; min-width: 200px; }
        .inv-label { display: block; font-weight: 600; margin-bottom: 5px; color: #2c3338; }
        .inv-select, .inv-input { width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #8c8f94; }
        .inv-table th { background: #f6f7f7; padding: 12px; text-align: left; border-bottom: 1px solid #ccd0d4; }
        .inv-table td { padding: 12px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
        .badge-pendiente { background: #fdf2cd; color: #856404; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 12px;}
        .badge-anulado { background: #f8d7da; color: #721c24; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 12px;}
    </style>

    <div class="wrap inv-wrap">
        <h1 style="font-size: 28px; margin-bottom: 20px;">Control de Inventarios</h1>
        <?php echo $mensaje; ?>

        <div class="inv-card" style="border-left: 5px solid #007cba;">
            <h2>📚 Base Maestra Consolidada</h2>
            <form method="post" enctype="multipart/form-data" class="inv-row" style="align-items: center;">
                <div class="inv-col"><input type="file" name="csv_maestra" accept=".csv" required class="inv-input"></div>
                <div class="inv-col" style="flex: 0.8;"><label><input type="checkbox" name="reemplazar_maestra" value="1"> Reemplazar lista</label></div>
                <div class="inv-col" style="flex: 0.5;"><button type="submit" name="cargar_maestra" class="button button-primary">Cargar</button></div>
            </form>
            <p style="margin-top:10px; font-size:13px; color:#666;">Total productos: <strong><?php echo $total_maestra; ?></strong></p>
        </div>

        <?php if ( isset($_GET['editar']) ) : 
            $edit_id = intval($_GET['editar']);
            $conteo_a_editar = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabla_conteos WHERE id = %d", $edit_id));
            if($conteo_a_editar):
        ?>
            <div class="inv-card" style="border-top: 4px solid #d63638;">
                <h2>✏️ Modificar Asignación #<?php echo $edit_id; ?></h2>
                <form method="post" action="?page=invfacil-admin">
                    <input type="hidden" name="conteo_id" value="<?php echo $edit_id; ?>">
                    <div class="inv-row">
                        <div class="inv-col">
                            <label class="inv-label">Verificador</label>
                            <select name="verificador_id" class="inv-select" required>
                                <?php foreach ( $verificadores as $v ) {
                                    $nombre_v = trim($v->first_name . ' ' . $v->last_name);
                                    $nombre_v = !empty($nombre_v) ? $nombre_v : $v->display_name;
                                    // Para el formulario de Editar:
                                    if(isset($conteo_a_editar)) {
                                        echo "<option value='{$v->ID}' ".selected($conteo_a_editar->verificador_id, $v->ID, false).">{$nombre_v}</option>";
                                    } else {
                                    // Para el formulario de Nueva Asignación:
                                        echo "<option value='{$v->ID}'>{$nombre_v}</option>";
                                    }
                                } ?>
                            </select>
                        </div>
                        <div class="inv-col">
                            <label class="inv-label">Jefe de Punto</label>
                            <select name="jefe_id" class="inv-select" required>
                                <?php foreach ( $jefes as $j ) {
                                    $nombre_j = trim($j->first_name . ' ' . $j->last_name);
                                    $nombre_j = !empty($nombre_j) ? $nombre_j : $j->display_name;
                                    // Para el formulario de Editar:
                                    if(isset($conteo_a_editar)) {
                                        echo "<option value='{$j->ID}' ".selected($conteo_a_editar->jefe_id, $j->ID, false).">{$nombre_j}</option>";
                                    } else {
                                    // Para el formulario de Nueva Asignación:
                                        echo "<option value='{$j->ID}'>{$nombre_j}</option>";
                                    }
                                } ?>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top: 20px;">
                        <button type="submit" name="invfacil_actualizar" class="button button-primary">Actualizar</button>
                        <a href="?page=invfacil-admin" class="button">Cancelar</a>
                    </div>
                </form>
            </div>
        <?php endif; else: ?>
            
            <div class="inv-card">
                <h2>📋 Nueva Asignación de Inventario</h2>
                <form method="post" enctype="multipart/form-data">
                    <div class="inv-row">
                        <div class="inv-col">
                            <label class="inv-label">Punto de Atención</label>
                            <select name="punto_id" class="inv-select" required>
                                <option value="">-- Seleccionar --</option>
                                <?php foreach ( $puntos as $p ) echo "<option value='{$p->id}'>{$p->nombre_punto}</option>"; ?>
                            </select>
                        </div>
                        <div class="inv-col">
                            <label class="inv-label">Archivo CSV</label>
                            <input type="file" name="csv_productos" accept=".csv" class="inv-input" required>
                        </div>
                    </div>
                    <div class="inv-row">
                        <div class="inv-col">
                            <label class="inv-label">Verificador</label>
                            <select name="verificador_id" class="inv-select" required>
                                <option value="">-- Seleccionar --</option>
                                <?php foreach ( $verificadores as $v ) {
                                    $nombre_v = trim($v->first_name . ' ' . $v->last_name);
                                    $nombre_v = !empty($nombre_v) ? $nombre_v : $v->display_name;
                                    // Para el formulario de Editar:
                                    if(isset($conteo_a_editar)) {
                                        echo "<option value='{$v->ID}' ".selected($conteo_a_editar->verificador_id, $v->ID, false).">{$nombre_v}</option>";
                                    } else {
                                    // Para el formulario de Nueva Asignación:
                                        echo "<option value='{$v->ID}'>{$nombre_v}</option>";
                                    }
                                } ?>
                            </select>
                        </div>
                        <div class="inv-col">
                            <label class="inv-label">Jefe de Punto</label>
                            <select name="jefe_id" class="inv-select" required>
                                <option value="">-- Seleccionar --</option>
                                <?php foreach ( $jefes as $j ) {
                                    $nombre_j = trim($j->first_name . ' ' . $j->last_name);
                                    $nombre_j = !empty($nombre_j) ? $nombre_j : $j->display_name;
                                    // Para el formulario de Editar:
                                    if(isset($conteo_a_editar)) {
                                        echo "<option value='{$j->ID}' ".selected($conteo_a_editar->jefe_id, $j->ID, false).">{$nombre_j}</option>";
                                    } else {
                                    // Para el formulario de Nueva Asignación:
                                        echo "<option value='{$j->ID}'>{$nombre_j}</option>";
                                    }
                                } ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="invfacil_asignar" class="button button-primary" style="margin-top:15px;">Crear Asignación</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="inv-card">
            <h2>📊 Inventarios en Proceso</h2>
            <table class="inv-table" style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr><th>Fecha</th><th>Punto</th><th>Verificador</th><th>Estado</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                    <?php if($conteos): foreach($conteos as $c): 
                        if ($c->estado == 'enviado_azsign') continue;
                        $pto = $wpdb->get_row($wpdb->prepare("SELECT nombre_punto FROM $tabla_puntos WHERE id = %d", $c->punto_id));
                        $v_user = get_userdata(intval($c->verificador_id));
                    ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($c->fecha_asignacion)); ?></td>
                        <td><strong><?php echo $pto ? $pto->nombre_punto : 'N/A'; ?></strong></td>
                        <td><?php echo $v_user ? $v_user->display_name : 'N/A'; ?></td>
                        <td><span class="badge-pendiente">Intento <?php echo $c->intento; ?></span></td>
                        <td>
                            <a href="?page=invfacil-admin&editar=<?php echo $c->id; ?>" class="button button-small">Editar</a>
                            <a href="?page=invfacil-admin&borrar=<?php echo $c->id; ?>" style="color: #d63638; margin-left:10px; font-size:12px;" onclick="return confirm('¿Borrar?');">Borrar</a>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="5" style="text-align:center;">No hay asignaciones activas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
