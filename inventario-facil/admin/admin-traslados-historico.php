<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function invfacil_pantalla_traslados_historico() {
    global $wpdb;
    $tabla_traslados = $wpdb->prefix . 'invfacil_traslados';
    $tabla_bodegas   = $wpdb->prefix . 'invfacil_bodegas';
    $mensaje = '';

    // Marcar como procesado en ERP
    if ( isset($_GET['marcar_procesado']) ) {
        $id_traslado = intval($_GET['marcar_procesado']);
        $wpdb->update($tabla_traslados, array('estado' => 'procesado_erp'), array('id' => $id_traslado));
        $mensaje = '<div class="notice notice-success is-dismissible"><p>Traslado marcado como Procesado en el ERP.</p></div>';
    }

    // Filtro de vista (Pendientes vs Histórico Completo)
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
                        <tr>
                            <th>ID Traslado</th>
                            <th>Fecha</th>
                            <th>Bodega Origen (Salida)</th>
                            <th>Bodega Destino (Entrada)</th>
                            <th>Estado</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($traslados as $t): 
                            $origen = $wpdb->get_row($wpdb->prepare("SELECT nombre_bodega FROM $tabla_bodegas WHERE id = %d", $t->bodega_origen_id));
                            $destino = $wpdb->get_row($wpdb->prepare("SELECT nombre_bodega FROM $tabla_bodegas WHERE id = %d", $t->bodega_destino_id));
                        ?>
                        <tr>
                            <td><strong>#TR-<?php echo $t->id; ?></strong></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($t->fecha_traslado)); ?></td>
                            <td><?php echo $origen ? esc_html($origen->nombre_bodega) : 'N/A'; ?><br><small style="color:#666;"><?php echo esc_html($t->tipo_salida); ?></small></td>
                            <td><?php echo $destino ? esc_html($destino->nombre_bodega) : 'N/A'; ?><br><small style="color:#666;"><?php echo esc_html($t->tipo_entrada); ?></small></td>
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
            <?php else: ?>
                <p style="text-align: center; color: #666; font-size: 16px;">No hay traslados en esta vista.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}