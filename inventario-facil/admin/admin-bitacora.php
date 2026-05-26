<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function invfacil_pantalla_bitacora() {
    // Doble validación de seguridad: Si no es auditor, lo expulsa
    if ( ! current_user_can('ver_bitacora') ) {
        wp_die( 'No tienes permisos suficientes para acceder a esta página.' );
    }

    global $wpdb;
    $tabla_bitacora = $wpdb->prefix . 'invfacil_bitacora';

    // Obtener los últimos 500 registros para no saturar la memoria
    $registros = $wpdb->get_results("SELECT * FROM $tabla_bitacora ORDER BY fecha_hora DESC LIMIT 500");

    ?>
    <div class="wrap" style="max-width: 1200px; margin-top: 20px;">
        <h1 style="font-size: 28px; margin-bottom: 5px;">🕵️ Bitácora de Auditoría</h1>
        <p style="font-size: 16px; color: #666; margin-bottom: 20px;">Registro inmutable de movimientos del sistema. Acceso exclusivo para el rol de Auditor.</p>

        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.04);">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr style="background-color: #1d2327; color: #fff;">
                        <th style="color: #fff; padding: 15px; width: 15%;">Fecha y Hora</th>
                        <th style="color: #fff; padding: 15px; width: 15%;">Usuario</th>
                        <th style="color: #fff; padding: 15px; width: 15%;">Rol</th>
                        <th style="color: #fff; padding: 15px; width: 15%;">Dirección IP</th>
                        <th style="color: #fff; padding: 15px; width: 40%;">Acción Realizada</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($registros): foreach($registros as $r): ?>
                        <tr>
                            <td><strong><?php echo date('d/m/Y h:i A', strtotime($r->fecha_hora)); ?></strong></td>
                            <td><span style="color: #007cba; font-weight: bold;">@<?php echo esc_html($r->usuario); ?></span></td>
                            <td><span style="background: #f0f6fc; padding: 3px 6px; border-radius: 4px; font-size: 11px;"><?php echo esc_html(str_replace('invfacil_', '', $r->rol)); ?></span></td>
                            <td><code style="background: #f6f7f7; color: #d63638;"><?php echo esc_html($r->ip); ?></code></td>
                            <td><?php echo esc_html($r->accion); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" style="text-align: center; padding: 20px; color: #666;">No hay registros en la bitácora todavía.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}