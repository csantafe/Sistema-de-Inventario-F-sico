<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function invfacil_pantalla_puntos() {
    global $wpdb;
    $tabla_puntos = $wpdb->prefix . 'invfacil_puntos';
    $mensaje = '';

    // Procesar guardado de nuevo punto
    if ( isset($_POST['invfacil_guardar_punto']) ) {
        $nombre_punto = sanitize_text_field($_POST['nombre_punto']);
        $jefe_id = intval($_POST['jefe_id']);
        
        $wpdb->insert($tabla_puntos, array(
            'nombre_punto' => $nombre_punto,
            'jefe_id'      => $jefe_id
        ));
        $mensaje = '<div class="notice notice-success is-dismissible"><p>Punto de Atención creado exitosamente.</p></div>';
    }

    $jefes = get_users( array( 'role' => 'invfacil_jefe' ) );
    $puntos_existentes = $wpdb->get_results("SELECT * FROM $tabla_puntos ORDER BY id DESC");

    echo $mensaje;
    ?>
    <style>
        .inv-wrap { max-width: 1000px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .inv-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; padding: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); margin-bottom: 20px; }
        .inv-card h2 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 15px; color: #1d2327; }
        .inv-form-group { margin-bottom: 20px; }
        .inv-form-group label { display: block; font-weight: 600; margin-bottom: 8px; }
        .inv-form-group input, .inv-form-group select { width: 100%; max-width: 400px; padding: 8px; border-radius: 4px; border: 1px solid #8c8f94; }
        .inv-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .inv-table th, .inv-table td { text-align: left; padding: 12px; border-bottom: 1px solid #f0f0f1; }
        .inv-table th { background: #f6f7f7; font-weight: 600; }
    </style>

    <div class="inv-wrap">
        <h1 style="font-size: 28px; font-weight: 600; margin-bottom: 20px;">Gestión de Puntos de Atención</h1>
        
        <div class="inv-card">
            <h2>+ Crear Nuevo Punto</h2>
            <form method="post">
                <div class="inv-form-group">
                    <label>Nombre del Punto de Atención (Sede)</label>
                    <input type="text" name="nombre_punto" required placeholder="Ej: Sede Principal Centro">
                </div>
                <div class="inv-form-group">
                    <label>Asignar Jefe de Punto / Producción</label>
                    <select name="jefe_id" required>
                        <option value="">-- Seleccione al Jefe Responsable --</option>
                        <?php foreach ( $jefes as $j ) {
                            $nombre_j = trim($j->first_name . ' ' . $j->last_name);
                            $nombre_j = !empty($nombre_j) ? $nombre_j : $j->display_name;
                            echo "<option value='{$j->ID}'>{$nombre_j} ({$j->user_email})</option>";
                        } ?>
                    </select>
                </div>
                <button type="submit" name="invfacil_guardar_punto" class="button button-primary button-large">Guardar Punto de Atención</button>
            </form>
        </div>

        <div class="inv-card">
            <h2>Puntos Registrados</h2>
            <table class="inv-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre del Punto</th>
                        <th>Jefe Asignado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($puntos_existentes): foreach($puntos_existentes as $p): 
                        $info_jefe = get_userdata($p->jefe_id);
                    ?>
                        <tr>
                            <td><?php echo $p->id; ?></td>
                            <td><strong><?php echo esc_html($p->nombre_punto); ?></strong></td>
                            <td><?php echo $info_jefe ? esc_html($info_jefe->display_name) : 'Usuario Eliminado'; ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="3">No hay puntos registrados aún.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
