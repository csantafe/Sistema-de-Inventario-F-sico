<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function invfacil_pantalla_bodegas() {
    global $wpdb;
    $tabla_bodegas  = $wpdb->prefix . 'invfacil_bodegas';
    $tabla_prod_erp = $wpdb->prefix . 'invfacil_productos_erp';
    $mensaje = '';

    // 1. Procesar Guardado de Bodega
    if ( isset($_POST['guardar_bodega']) ) {
        $nombre = sanitize_text_field($_POST['nombre_bodega']);
        $resp   = intval($_POST['responsable_id']);
        $wpdb->insert($tabla_bodegas, array('nombre_bodega' => $nombre, 'responsable_id' => $resp));
        $mensaje = '<div class="notice notice-success is-dismissible"><p>Bodega creada exitosamente.</p></div>';
    }

    // 2. Procesar Carga de Productos ERP (CSV Blindado)
    if ( isset($_POST['cargar_productos']) && isset($_FILES['csv_productos']) ) {
        ini_set('auto_detect_line_endings', TRUE); 
        $file = $_FILES['csv_productos']['tmp_name'];
        $cargados = 0;
        
        if ( ($handle = fopen($file, "r")) !== FALSE ) {
            // Opcional: Vaciar la tabla antes de cargar para mantenerla actualizada con el ERP
            if(isset($_POST['reemplazar_todo'])) { $wpdb->query("TRUNCATE TABLE $tabla_prod_erp"); }

            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
                
                // Truco para Excel en Español: Si la fila tiene 1 sola columna y contiene un punto y coma, la separamos nosotros
                if (count($data) == 1 && strpos($data[0], ';') !== false) {
                    $data = explode(';', $data[0]);
                }

                // Validamos que existan al menos 2 columnas en esta fila antes de leerlas
                if ( isset($data[0]) && isset($data[1]) ) {
                    $codigo = sanitize_text_field(trim($data[0])); 
                    $nombre = sanitize_text_field(trim($data[1])); 
                    
                    // Solo guardamos si el código y el nombre no están vacíos
                    if (!empty($codigo) && !empty($nombre) && strtolower($codigo) !== 'codigo') {
                        // REPLACE previene errores si el código (UNIQUE) ya existe
                        $wpdb->query($wpdb->prepare("REPLACE INTO $tabla_prod_erp (codigo, nombre) VALUES (%s, %s)", $codigo, $nombre));
                        $cargados++;
                    }
                }
            }
            fclose($handle);
            
            if ($cargados > 0) {
                $mensaje = "<div class='notice notice-success is-dismissible'><p><strong>¡Éxito!</strong> Se cargaron/actualizaron $cargados productos del ERP.</p></div>";
            } else {
                $mensaje = "<div class='notice notice-warning is-dismissible'><p><strong>Advertencia:</strong> El archivo se leyó, pero no se encontraron productos válidos. Asegúrese de que el archivo tenga dos columnas (Código y Nombre).</p></div>";
            }
        }
    }

    $usuarios = get_users();
    $bodegas_existentes = $wpdb->get_results("SELECT * FROM $tabla_bodegas ORDER BY nombre_bodega ASC");
    $total_productos = $wpdb->get_var("SELECT COUNT(*) FROM $tabla_prod_erp");

    echo $mensaje;
    ?>
    <div class="wrap" style="max-width: 900px; margin-top: 20px;">
        <h1 style="font-size: 28px; margin-bottom: 20px;">Configuración de Traslados</h1>
        
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
            <h2>🏢 Crear Bodega y Asignar Responsable</h2>
            <form method="post" style="display:flex; gap:15px; align-items:flex-end; margin-bottom: 20px;">
                <div style="flex:1;">
                    <label style="font-weight:bold; display:block;">Nombre de Bodega</label>
                    <input type="text" name="nombre_bodega" required style="width:100%;">
                </div>
                <div style="flex:1;">
                    <label style="font-weight:bold; display:block;">Responsable (Firma AZSign)</label>
                    <select name="responsable_id" required style="width:100%;">
                        <option value="">-- Seleccione Usuario --</option>
                        <?php foreach ( $usuarios as $u ) echo "<option value='{$u->ID}'>{$u->display_name}</option>"; ?>
                    </select>
                </div>
                <button type="submit" name="guardar_bodega" class="button button-primary">Guardar Bodega</button>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>ID</th><th>Nombre de Bodega</th><th>Responsable Asignado</th></tr></thead>
                <tbody>
                    <?php if($bodegas_existentes): foreach($bodegas_existentes as $b): $resp = get_userdata($b->responsable_id); ?>
                        <tr><td><?php echo $b->id; ?></td><td><strong><?php echo esc_html($b->nombre_bodega); ?></strong></td><td><?php echo $resp ? esc_html($resp->display_name) : 'N/A'; ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="3">No hay bodegas creadas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 8px;">
            <h2>📦 Cargar Base de Productos del ERP</h2>
            <p>Sube un archivo CSV con 2 columnas: <strong>Columna A (Código)</strong> y <strong>Columna B (Nombre del Producto)</strong>.</p>
            <p>Total de productos actualmente en el sistema: <strong><?php echo $total_productos; ?></strong></p>
            
            <form method="post" enctype="multipart/form-data" style="background:#f0f6fc; padding:15px; border:2px dashed #007cba; border-radius:6px;">
                <input type="file" name="csv_productos" accept=".csv" required>
                <label style="margin-left: 20px;"><input type="checkbox" name="reemplazar_todo" value="1"> ⚠️ Borrar productos anteriores y reemplazar con este archivo</label>
                <br><br>
                <button type="submit" name="cargar_productos" class="button button-primary">Subir y Actualizar Productos</button>
            </form>
        </div>
    </div>
    <?php
}