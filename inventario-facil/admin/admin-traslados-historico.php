<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ========================================================================
// PANTALLA 1: TRASLADOS ERP
// ========================================================================
function invfacil_pantalla_traslados_historico() {
    global $wpdb;
    $tabla_traslados = $wpdb->prefix . 'invfacil_traslados';
    $tabla_bodegas   = $wpdb->prefix . 'invfacil_bodegas';
    $mensaje = '';

    if ( isset($_GET['marcar_procesado']) ) {
        $id_traslado = intval($_GET['marcar_procesado']);
        $wpdb->update($tabla_traslados, array('estado' => 'procesado_erp'), array('id' => $id_traslado));
        $mensaje = '<div class="notice notice-success is-dismissible"><p>Traslado marcado como Procesado en el ERP.</p></div>';
    }

    $ver_todos = isset($_GET['ver_todos']) ? true : false;
    $condicion = $ver_todos ? "1=1" : "estado = 'pendiente_erp'";
    $traslados = $wpdb->get_results("SELECT * FROM $tabla_traslados WHERE $condicion ORDER BY fecha_traslado DESC");

    echo $mensaje;
    ?>
    <div class="wrap" style="max-width: 1100px; margin-top: 20px;">
        <h1 style="font-size: 28px; margin-bottom: 10px;">Control de Traslados (ERP)</h1>
        <div style="margin-bottom: 20px;">
            <a href="?page=invfacil-traslados-hist" class="button <?php echo !$ver_todos ? 'button-primary' : ''; ?>">Pendientes de Procesar en ERP</a>
            <a href="?page=invfacil-traslados-hist&ver_todos=1" class="button <?php echo $ver_todos ? 'button-primary' : ''; ?>">Ver Histórico Completo</a>
        </div>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; padding: 25px;">
            <?php if($traslados): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr><th>ID Traslado</th><th>Fecha</th><th>Origen (Salida)</th><th>Destino (Entrada)</th><th>Estado</th><th style="text-align: center;">Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($traslados as $t): 
                            // 🚀 Leemos directamente el texto guardado
                            $origen_print = !empty($t->bodega_origen_nombre) ? $t->bodega_origen_nombre : 'N/A';
                            $destino_print = !empty($t->bodega_destino_nombre) ? $t->bodega_destino_nombre : 'N/A';
                        ?>
                        <tr>
                            <td><strong>#TR-<?php echo $t->id; ?></strong></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($t->fecha_traslado)); ?></td>
                            <td><?php echo esc_html($origen_print); ?><br><small style="color:#666;"><?php echo esc_html($t->tipo_salida); ?></small></td>
                            <td><?php echo esc_html($destino_print); ?><br><small style="color:#666;"><?php echo esc_html($t->tipo_entrada); ?></small></td>
                            <td>
                                <?php if($t->estado == 'pendiente_erp'): ?>
                                    <span style="background:#fff8e5; color:#d63638; padding:3px 8px; border-radius:3px;">Pendiente en ERP</span>
                                <?php else: ?>
                                    <span style="background:#d4edda; color:#155724; padding:3px 8px; border-radius:3px;">Procesado ERP</span>
                                <?php endif; ?>
                            </td>
                            <td style="width: 200px;">
                                <a href="<?php echo esc_url(add_query_arg('descargar_pdf_traslado', $t->id, admin_url('admin.php?page=invfacil-traslados-hist'))); ?>" class="button" target="_blank" style="width: 100%; text-align: center; margin-bottom: 5px;">📄 Visualizar PDF</a>
                                <a href="<?php echo esc_url(add_query_arg('exportar_csv_traslado', $t->id, admin_url('admin.php?page=invfacil-traslados-hist'))); ?>" class="button" style="width: 100%; text-align: center; margin-bottom: 5px; color: #155724; border-color: #c3e6cb; background: #d4edda;">📊 Exportar a Excel</a>
                                <?php if($t->estado == 'pendiente_erp'): ?>
                                    <a href="<?php echo esc_url(add_query_arg('marcar_procesado', $t->id)); ?>" class="button button-primary" style="width: 100%; text-align: center;" onclick="return confirm('¿Seguro que ya digitó este traslado en el ERP?');">✅ Marcar Procesado</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?><p style="text-align: center; color: #666; font-size: 16px;">No hay traslados en esta vista.</p><?php endif; ?>
        </div>
    </div>
    <?php
}

// ========================================================================
// PANTALLA 2: RECEPCIONES ERP (SOLUCIÓN BUG 1)
// ========================================================================
function invfacil_pantalla_recepciones_historico() {
    global $wpdb;
    $t_rec = $wpdb->prefix . 'invfacil_recepciones';
    $mensaje = '';

    if ( isset($_GET['marcar_procesado_rec']) ) {
        $id_rec = intval($_GET['marcar_procesado_rec']);
        $wpdb->update($t_rec, array('estado_erp' => 'procesado_erp'), array('id' => $id_rec));
        $mensaje = '<div class="notice notice-success is-dismissible"><p>Recepción marcada como Procesada en el ERP.</p></div>';
    }

    $ver_todos = isset($_GET['ver_todos_rec']) ? true : false;
    $condicion = $ver_todos ? "1=1" : "estado_erp = 'pendiente_erp'";
    
    // Validar si la tabla existe
    if($wpdb->get_var("SHOW TABLES LIKE '$t_rec'") != $t_rec) { echo "<div class='wrap'><h2>Actualice la Base de Datos</h2><p>Por favor, vaya a la sección de Plugins, <b>Desactive y vuelva a Activar</b> el plugin Inventario Fácil para generar las tablas.</p></div>"; return; }

    $recepciones = $wpdb->get_results("SELECT * FROM $t_rec WHERE $condicion ORDER BY fecha_recepcion DESC");

    echo $mensaje;
    ?>
    <div class="wrap" style="max-width: 1100px; margin-top: 20px;">
        <h1 style="font-size: 28px; margin-bottom: 10px;">Control de Recepciones (Firmadas)</h1>
        <p>Historial de actas parciales o completas validadas mediante el doble pad digital.</p>
        
        <div style="margin-bottom: 20px;">
            <a href="?page=invfacil-recepciones" class="button <?php echo !$ver_todos ? 'button-primary' : ''; ?>">Pendientes Procesar en ERP</a>
            <a href="?page=invfacil-recepciones&ver_todos_rec=1" class="button <?php echo $ver_todos ? 'button-primary' : ''; ?>">Ver Histórico Completo</a>
        </div>

        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; padding: 25px;">
            <?php if($recepciones): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Número (ERP)</th>
                            <th>Fecha de Recepción</th>
                            <th>Bodega Destino</th>
                            <th>Entregado Por</th>
                            <th>Recibido Por</th>
                            <th>Estado ERP</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recepciones as $r): 
                            $entregador = get_userdata($r->entregador_id);
                        ?>
                        <tr>
                            <td style="font-size: 16px;"><strong>#REC-<?php echo esc_html($r->nume_erp); ?></strong></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($r->fecha_recepcion)); ?></td>
                            <td><?php echo esc_html($r->bodega_destino); ?></td>
                            <td><?php echo $entregador ? esc_html($entregador->display_name) : 'Desconocido'; ?></td>
                            <td><strong><?php echo esc_html($r->nombre_recibe); ?></strong></td>
                            <td>
                                <?php if($r->estado_erp == 'pendiente_erp'): ?>
                                    <span style="background:#fff8e5; color:#d63638; padding:3px 8px; border-radius:3px; font-weight:bold;">Pendiente ERP</span>
                                <?php else: ?>
                                    <span style="background:#d4edda; color:#155724; padding:3px 8px; border-radius:3px; font-weight:bold;">Procesado</span>
                                <?php endif; ?>
                            </td>
                            <td style="width: 150px;">
                                <a href="<?php echo esc_url(add_query_arg('descargar_pdf_recepcion', $r->id, site_url())); ?>" class="button" target="_blank" style="width: 100%; text-align: center; margin-bottom: 5px;">📄 PDF Firmado</a>
                                <?php if($r->estado_erp == 'pendiente_erp'): ?>
                                    <a href="<?php echo esc_url(add_query_arg('marcar_procesado_rec', $r->id, admin_url('admin.php?page=invfacil-recepciones'))); ?>" class="button button-primary" style="width: 100%; text-align: center;" onclick="return confirm('¿Seguro que ya digitó la recepción en el ERP?');">✅ Marcar Procesado</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; color: #666; font-size: 16px;">No hay recepciones en esta vista.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
