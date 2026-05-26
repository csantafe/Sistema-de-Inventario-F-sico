<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function invfacil_pantalla_historico() {
    global $wpdb;
    $tabla_conteos = $wpdb->prefix . 'invfacil_conteos';
    $tabla_puntos  = $wpdb->prefix . 'invfacil_puntos';

    // Obtener los inventarios que ya fueron elaborados (enviados a AZSign)
    $historicos = $wpdb->get_results("SELECT * FROM $tabla_conteos WHERE estado = 'enviado_azsign' ORDER BY fecha_asignacion DESC");
    ?>
    <div class="wrap" style="max-width: 1000px; margin-top: 20px;">
        <h1 style="font-size: 28px; font-weight: 600; margin-bottom: 10px;">Histórico de Inventarios</h1>
        <p style="font-size: 16px;">Aquí se muestran los inventarios elaborados. Puede <strong>Exportar los datos a CSV</strong> inmediatamente para su ERP, sin importar si AZSign ya recolectó las firmas.</p>

        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; padding: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); margin-top: 20px;">
            <?php if($historicos): ?>
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="background-color: #f6f7f7; color: #1d2327;">
                            <th style="padding: 15px; border-bottom: 1px solid #e2e4e7; text-align: left;">ID AZSign</th>
                            <th style="padding: 15px; border-bottom: 1px solid #e2e4e7; text-align: left;">Punto / Sede</th>
                            <th style="padding: 15px; border-bottom: 1px solid #e2e4e7; text-align: left;">Verificador</th>
                            <th style="padding: 15px; border-bottom: 1px solid #e2e4e7; text-align: left;">Jefe Responsable</th>
                            <th style="padding: 15px; border-bottom: 1px solid #e2e4e7; text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($historicos as $h): 
                            $pto = $wpdb->get_row($wpdb->prepare("SELECT nombre_punto FROM $tabla_puntos WHERE id = %d", $h->punto_id));
                            $v_user = get_userdata($h->verificador_id);
                            $j_user = get_userdata($h->jefe_id);
                        ?>
                        <tr>
                            <td style="padding: 15px; border-bottom: 1px solid #e2e4e7; font-size: 11px; color: #666;"><?php echo esc_html($h->azsign_acuerdo_id); ?></td>
                            <td style="padding: 15px; border-bottom: 1px solid #e2e4e7;"><strong><?php echo $pto ? esc_html($pto->nombre_punto) : 'N/A'; ?></strong></td>
                            <td style="padding: 15px; border-bottom: 1px solid #e2e4e7;"><?php echo $v_user ? esc_html($v_user->display_name) : 'N/A'; ?></td>
                            <td style="padding: 15px; border-bottom: 1px solid #e2e4e7;"><?php echo $j_user ? esc_html($j_user->display_name) : 'N/A'; ?></td>
                            <td style="padding: 15px; border-bottom: 1px solid #e2e4e7; width: 220px;">
                                
                                <a href="<?php echo esc_url(add_query_arg('exportar_csv_inventario', $h->id, admin_url('admin.php?page=invfacil-historico'))); ?>" class="button button-primary" style="width: 100%; text-align: center; margin-bottom: 8px; background: #135e96; border-color: #135e96;">📊 Exportar Datos (CSV)</a>

                                <?php if(!empty($h->azsign_acuerdo_id)): ?>
                                    <a href="<?php echo esc_url(add_query_arg('descargar_pdf_azsign', $h->id, admin_url('admin.php?page=invfacil-historico'))); ?>" class="button" target="_blank" style="width: 100%; text-align: center;">📄 Descargar PDF Firmado</a>
                                <?php else: ?>
                                    <span style="color:#d63638; display:block; text-align:center;">Sin ID AZSign</span>
                                <?php endif; ?>
                                
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; color: #666;">No hay inventarios terminados todavía.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}