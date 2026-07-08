<?php
/**
 * Plugin Name: Inventario y Traslados Fácil
 * Description: Control de inventarios y traslados con integración AZSign y Bitácora de Auditoría.
 * Version: 6.5
 * Author: Carlos Santafe
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ========================================================================
// FUNCIÓN GLOBAL PARA REGISTRAR ACCIONES EN LA BITÁCORA
// ========================================================================
function invfacil_registrar_bitacora($accion) {
    if (!is_user_logged_in()) return;
    
    global $wpdb;
    $tabla_bitacora = $wpdb->prefix . 'invfacil_bitacora';
    $user = wp_get_current_user();
    
    $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];
    $fecha_hora = current_time('mysql'); 
    
    if($wpdb->get_var("SHOW TABLES LIKE '$tabla_bitacora'") == $tabla_bitacora) {
        $wpdb->insert($tabla_bitacora, array(
            'usuario'    => $user->user_login,
            'rol'        => implode(', ', $user->roles),
            'ip'         => $ip,
            'accion'     => sanitize_text_field($accion),
            'fecha_hora' => $fecha_hora
        ));
    }
}

// ========================================================================
// ACTIVACIÓN Y CREACIÓN DE TABLAS (Sistema de Auto-Reparación)
// ========================================================================
function invfacil_activar_plugin() {
    add_role( 'invfacil_admin', 'Administrador de Módulos', array( 'read' => true, 'manage_options' => true ) );
    add_role( 'invfacil_jefe', 'Jefe de Punto', array( 'read' => true ) );
    add_role( 'invfacil_verificador', 'Verificador de Inventarios', array( 'read' => true ) );
    add_role( 'invfacil_auditor', 'Auditor de Módulos', array( 'read' => true, 'manage_options' => true, 'ver_bitacora' => true ) );

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    $t_puntos    = $wpdb->prefix . 'invfacil_puntos';
    $t_conteos   = $wpdb->prefix . 'invfacil_conteos';
    $t_items     = $wpdb->prefix . 'invfacil_conteo_items';
    $t_bodegas   = $wpdb->prefix . 'invfacil_bodegas';
    $t_erp       = $wpdb->prefix . 'invfacil_productos_erp';
    $t_traslados = $wpdb->prefix . 'invfacil_traslados';
    $t_tras_it   = $wpdb->prefix . 'invfacil_traslado_items';
    $t_bitacora  = $wpdb->prefix . 'invfacil_bitacora';

    $sql1 = "CREATE TABLE $t_puntos (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      nombre_punto varchar(255) NOT NULL,
      jefe_id bigint(20) NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql1 );

    $sql2 = "CREATE TABLE $t_conteos (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      punto_id mediumint(9) NOT NULL,
      verificador_id bigint(20) NOT NULL,
      jefe_id bigint(20) NOT NULL,
      fecha_asignacion datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
      estado varchar(50) DEFAULT 'pendiente' NOT NULL,
      intento int(11) DEFAULT 1 NOT NULL,
      azsign_acuerdo_id varchar(255),
      PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql2 );

    $sql3 = "CREATE TABLE $t_items (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      conteo_id mediumint(9) NOT NULL,
      codigo varchar(100) DEFAULT '' NOT NULL,
      nombre_producto varchar(255) NOT NULL,
      cantidad_esperada float DEFAULT 0 NOT NULL,
      unidad_sistema varchar(50) DEFAULT 'Unidad' NOT NULL,
      cantidad_ingresada float DEFAULT 0 NOT NULL,
      unidad_conteo varchar(50) DEFAULT 'Unidad' NOT NULL,
      cantidad float DEFAULT 0,
      fecha_vencimiento date DEFAULT NULL,
      observaciones text DEFAULT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql3 );

    $sql4 = "CREATE TABLE $t_bodegas (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      nombre_bodega varchar(255) NOT NULL,
      responsable_id bigint(20) NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql4 );

    $sql5 = "CREATE TABLE $t_erp (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      codigo varchar(100) NOT NULL,
      nombre varchar(255) NOT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY codigo (codigo)
    ) $charset_collate;";
    dbDelta( $sql5 );

    $sql6 = "CREATE TABLE $t_traslados (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      bodega_origen_id mediumint(9) NOT NULL,
      bodega_destino_id mediumint(9) NOT NULL,
      tipo_salida varchar(255) NOT NULL,
      tipo_entrada varchar(255) NOT NULL,
      fecha_traslado datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
      estado varchar(50) DEFAULT 'pendiente_erp' NOT NULL,
      azsign_acuerdo_id varchar(255),
      elaborador_id bigint(20) NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql6 );

    $sql7 = "CREATE TABLE $t_tras_it (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      traslado_id mediumint(9) NOT NULL,
      producto_codigo varchar(100) NOT NULL,
      producto_nombre varchar(255) NOT NULL,
      cantidad float NOT NULL,
      unidad_medida varchar(50) NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql7 );

    $sql8 = "CREATE TABLE $t_bitacora (
      id bigint(20) NOT NULL AUTO_INCREMENT,
      usuario varchar(255) NOT NULL,
      rol varchar(100) NOT NULL,
      ip varchar(100) NOT NULL,
      accion text NOT NULL,
      fecha_hora datetime NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql8 );
    
    if($wpdb->get_var("SHOW TABLES LIKE '$t_conteos'") == $t_conteos) {
        $col_intento = $wpdb->get_row("SHOW COLUMNS FROM $t_conteos LIKE 'intento'");
        if ( ! $col_intento ) {
            $wpdb->query("ALTER TABLE $t_conteos ADD intento int(11) DEFAULT 1 NOT NULL;");
        }
    }
    // AUTO-REPARACIÓN DE LA TABLA TRASLADOS PARA FIRMAS Y NOMBRES
    // ========================================================================
    if($wpdb->get_var("SHOW TABLES LIKE '$t_traslados'") == $t_traslados) {
        $col_fe = $wpdb->get_row("SHOW COLUMNS FROM $t_traslados LIKE 'firma_entrega'");
        if ( ! $col_fe ) {
            $wpdb->query("ALTER TABLE $t_traslados ADD firma_entrega longtext;");
            $wpdb->query("ALTER TABLE $t_traslados ADD firma_verifica longtext;");
        }
        $col_ne = $wpdb->get_row("SHOW COLUMNS FROM $t_traslados LIKE 'nombre_entrega'");
        if ( ! $col_ne ) {
            $wpdb->query("ALTER TABLE $t_traslados ADD nombre_entrega varchar(255) DEFAULT '' NOT NULL;");
            $wpdb->query("ALTER TABLE $t_traslados ADD nombre_recibe varchar(255) DEFAULT '' NOT NULL;");
            $wpdb->query("ALTER TABLE $t_traslados ADD motivo varchar(255) DEFAULT '' NOT NULL;");
            $wpdb->query("ALTER TABLE $t_traslados ADD bodega_origen_nombre varchar(255) DEFAULT '' NOT NULL;");
            $wpdb->query("ALTER TABLE $t_traslados ADD bodega_destino_nombre varchar(255) DEFAULT '' NOT NULL;");
        }
    }

    if($wpdb->get_var("SHOW TABLES LIKE '$t_items'") == $t_items) {
        $cols_items = array(
            'cantidad_esperada'  => "float DEFAULT 0 NOT NULL",
            'unidad_sistema'     => "varchar(50) DEFAULT 'Unidad' NOT NULL",
            'cantidad_ingresada' => "float DEFAULT 0 NOT NULL",
            'unidad_conteo'      => "varchar(50) DEFAULT 'Unidad' NOT NULL",
            'cantidad'           => "float DEFAULT 0"
        );
        
        foreach ( $cols_items as $col_nombre => $col_definicion ) {
            $existe = $wpdb->get_row("SHOW COLUMNS FROM $t_items LIKE '$col_nombre'");
            if ( ! $existe ) {
                $wpdb->query("ALTER TABLE $t_items ADD $col_nombre $col_definicion;");
            }
        }
    }
    // ========================================================================
    // TABLAS ACTUALIZADAS PARA DOBLE PAD Y RECEPCIÓN
    // ========================================================================
    $t_recepciones = $wpdb->prefix . 'invfacil_recepciones';
    $t_recepciones_it = $wpdb->prefix . 'invfacil_recepcion_items';

    $sql9 = "CREATE TABLE $t_recepciones (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      nume_erp varchar(50) NOT NULL,
      bodega_origen varchar(255) NOT NULL,
      bodega_destino varchar(255) NOT NULL,
      fecha_recepcion datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
      receptor_id bigint(20) NOT NULL,
      entregador_id bigint(20) DEFAULT 0 NOT NULL,
      nombre_recibe varchar(255) DEFAULT '' NOT NULL,
      firma_entrega longtext NOT NULL,
      firma_verifica longtext NOT NULL,
      estado_erp varchar(50) DEFAULT 'pendiente_erp' NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql9 );

    $sql10 = "CREATE TABLE $t_recepciones_it (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      recepcion_id mediumint(9) NOT NULL,
      codigo varchar(100) NOT NULL,
      nombre varchar(255) NOT NULL,
      unidad varchar(50) NOT NULL,
      cant_entregada float NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql10 );

    // Auto-Reparación de la tabla de recepciones
    if($wpdb->get_var("SHOW TABLES LIKE '$t_recepciones'") == $t_recepciones) {
        $col_recibe = $wpdb->get_row("SHOW COLUMNS FROM $t_recepciones LIKE 'nombre_recibe'");
        if ( ! $col_recibe ) {
            $wpdb->query("ALTER TABLE $t_recepciones ADD entregador_id bigint(20) DEFAULT 0 NOT NULL;");
            $wpdb->query("ALTER TABLE $t_recepciones ADD nombre_recibe varchar(255) DEFAULT '' NOT NULL;");
        }
    }
}

function invfacil_menu_admin() {
    $usuario = wp_get_current_user();
    $roles = (array) $usuario->roles;

    if ( array_intersect( ['administrator', 'invfacil_admin', 'invfacil_auditor'], $roles ) ) {
        add_menu_page( 'Inventario Fácil', 'Inventarios', 'read', 'invfacil-admin', 'invfacil_pantalla_admin', 'dashicons-clipboard', 6 );
        add_submenu_page( 'invfacil-admin', 'Puntos de Atención', 'Puntos de Atención', 'read', 'invfacil-puntos', 'invfacil_pantalla_puntos' );
        add_submenu_page( 'invfacil-admin', 'Histórico Inventarios', 'Histórico', 'read', 'invfacil-historico', 'invfacil_pantalla_historico' );
        
        add_menu_page( 'Traslados ERP', 'Traslados ERP', 'read', 'invfacil-traslados-hist', 'invfacil_pantalla_traslados_historico', 'dashicons-migrate', 7 );
        add_submenu_page( 'invfacil-traslados-hist', 'Bodegas y Productos', 'Configuración Traslados', 'read', 'invfacil-bodegas', 'invfacil_pantalla_bodegas' );
        add_submenu_page( 'invfacil-traslados-hist', 'Recepciones ERP', 'Recepciones ERP', 'read', 'invfacil-recepciones', 'invfacil_pantalla_recepciones_historico' );
    }

    if ( array_intersect( ['administrator', 'invfacil_auditor'], $roles ) ) {
        add_submenu_page( 'invfacil-admin', 'Bitácora de Auditoría', '🕵️ Bitácora (Auditor)', 'read', 'invfacil-bitacora', 'invfacil_pantalla_bitacora' );
    }
}
add_action( 'admin_menu', 'invfacil_menu_admin' );

function invfacil_bloquear_backend_operativos() {
    if ( is_admin() && ! wp_doing_ajax() ) {
        $usuario = wp_get_current_user();
        $roles = (array) $usuario->roles;
        
        if ( in_array( 'invfacil_verificador', $roles ) || in_array( 'invfacil_jefe', $roles ) ) {
            wp_redirect( home_url() );
            exit;
        }
    }
}
add_action( 'admin_init', 'invfacil_bloquear_backend_operativos' );

function invfacil_procesar_exportacion_csv_traslado() {
    if (isset($_GET['exportar_csv_traslado']) && current_user_can('manage_options')) {
        global $wpdb;
        $traslado_id     = intval($_GET['exportar_csv_traslado']);
        $tabla_traslados = $wpdb->prefix . 'invfacil_traslados';
        $tabla_bodegas   = $wpdb->prefix . 'invfacil_bodegas';
        $tabla_items     = $wpdb->prefix . 'invfacil_traslado_items';

        $t = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabla_traslados WHERE id = %d", $traslado_id));
        if($t) {
            $n_origen = $wpdb->get_var($wpdb->prepare("SELECT nombre_bodega FROM $tabla_bodegas WHERE id = %d", $t->bodega_origen_id));
            $n_destino = $wpdb->get_var($wpdb->prepare("SELECT nombre_bodega FROM $tabla_bodegas WHERE id = %d", $t->bodega_destino_id));
            $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tabla_items WHERE transcado_id = %d", $traslado_id));

            $nombre_archivo = "Traslado_ERP_" . $traslado_id . "_" . date('Ymd_Hi') . ".csv";
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
            
            $output = fopen('php://output', 'w');
            fputs($output, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) )); 
            
            fputcsv($output, array('CLUB MILITAR', '', 'REPORTE TRASLADO DE INVENTARIOS', '', '', '', 'CODIGO: AB-P10-F02'));
            fputcsv($output, array());
            fputcsv($output, array('TRASLADO INTERNO', '', '', 'FECHA:', date('d/m/Y H:i', strtotime($t->fecha_traslado))));
            fputcsv($output, array());
            fputcsv($output, array('MOVIMIENTO DE SALIDA'));
            fputcsv($output, array($t->tipo_salida, '', '', $t->tipo_entrada));
            fputcsv($output, array($n_origen, '', '', $n_destino));
            fputcsv($output, array());
            
            fputcsv($output, array('CODIGO', 'PRODUCTO', 'CANT.', 'UND.MEDIDA', 'DEVOLUCION', 'TOTAL'));
            
            foreach($items as $item) {
                fputcsv($output, array(
                    $item->producto_codigo,
                    $item->producto_nombre,
                    $item->cantidad,
                    $item->unidad_medida,
                    '', 
                    ''  
                ));
            }

            invfacil_registrar_bitacora("Exportó a Excel el Traslado #TR-$traslado_id (De '$n_origen' a '$n_destino')");
            fclose($output);
            exit;
        }
    }
}
add_action('init', 'invfacil_procesar_exportacion_csv_traslado');

function invfacil_procesar_descarga_pdf_traslado() {
        if (isset($_GET['descargar_pdf_traslado']) && current_user_can('manage_options')) {
            global $wpdb;
            $traslado_id     = intval($_GET['descargar_pdf_traslado']);
            $tabla_traslados = $wpdb->prefix . 'invfacil_traslados';
            $tabla_bodegas   = $wpdb->prefix . 'invfacil_bodegas';
            $tabla_items     = $wpdb->prefix . 'invfacil_traslado_items';

            $t = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabla_traslados WHERE id = %d", $traslado_id));
            if($t) {
                
                // 🚀 LIMPIEZA DE BUFFER: Previene el error de PDF corrupto
                if (ob_get_length()) { ob_end_clean(); }

                // 🚀 SOLUCIÓN AL BUG: Consultamos los productos del traslado (esta línea faltaba)
                $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tabla_items WHERE traslado_id = %d", $traslado_id));

                require_once plugin_dir_path( __FILE__ ) . 'includes/fpdf/fpdf.php';
                $pdf = new FPDF('P', 'mm', 'A4');
                $pdf->SetMargins(35, 10, 10); 
                $pdf->AddPage();
                
                // 🚀 Lógica de rescate de Nombres
                $n_origen = !empty($t->bodega_origen_nombre) ? $t->bodega_origen_nombre : 'Bodega No Identificada';
                $n_destino = !empty($t->bodega_destino_nombre) ? $t->bodega_destino_nombre : 'Bodega No Identificada';

                $n_origen_print = strlen($n_origen) > 42 ? substr($n_origen, 0, 42) . '...' : $n_origen;
                $n_destino_print = strlen($n_destino) > 42 ? substr($n_destino, 0, 42) . '...' : $n_destino;

                // Ancho total disponible: 165 mm
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
                $motivo_imprimir = !empty($t->motivo) ? $t->motivo : 'Traslado Interno';
                $pdf->Cell(90, 8, utf8_decode($motivo_imprimir), 1, 0, 'L');
                $pdf->Cell(90, 8, utf8_decode('Traslado Interno'), 1, 0, 'L');
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->Cell(20, 8, 'FECHA:', 1, 0, 'C');
                $pdf->SetFont('Arial', '', 9);
                $pdf->Cell(30, 8, date('d/m/Y', strtotime($t->fecha_traslado)), 1, 1, 'C');

                $pdf->Ln(5);
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->Cell(165, 8, 'MOVIMIENTO DE SALIDA', 1, 1, 'C');
                $pdf->Cell(82.5, 8, utf8_decode($t->tipo_salida), 1, 0, 'C');
                $pdf->Cell(82.5, 8, utf8_decode($t->tipo_entrada), 1, 1, 'C');
                $pdf->SetFont('Arial', '', 8);
                $pdf->Cell(82.5, 8, utf8_decode($n_origen), 1, 0, 'C');
                $pdf->Cell(82.5, 8, utf8_decode($n_destino), 1, 1, 'C');

                $pdf->Ln(5);
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->Cell(25, 8, 'CODIGO', 1, 0, 'C');
                $pdf->Cell(70, 8, 'PRODUCTO', 1, 0, 'C');
                $pdf->Cell(15, 8, 'CANT.', 1, 0, 'C');
                $pdf->Cell(25, 8, 'UND.MEDIDA', 1, 0, 'C');
                $pdf->Cell(30, 8, 'TOTAL', 1, 1, 'C');

                $pdf->SetFont('Arial', '', 8);
                foreach($items as $p) {
                    $pdf->Cell(25, 8, utf8_decode($p->producto_codigo), 1, 0, 'C');
                    $nombre_corto = strlen($p->producto_nombre) > 40 ? substr($p->producto_nombre, 0, 40) . '...' : $p->producto_nombre;
                    $pdf->Cell(70, 8, utf8_decode($nombre_corto), 1, 0, 'L');
                    $pdf->Cell(15, 8, $p->cantidad, 1, 0, 'C');
                    $pdf->Cell(25, 8, utf8_decode($p->unidad_medida), 1, 0, 'C');
                    $pdf->Cell(30, 8, '', 1, 1, 'C');
                }

                $pdf->Ln(10);
                $x_inicial = $pdf->GetX();
                $y_inicial = $pdf->GetY();
                
            // Renderizar Firma de Quien Entrega
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(82.5, 8, utf8_decode('FIRMA DE QUIEN ENTREGA:'), 0, 0, 'C');
            if (!empty($t->firma_entrega)) {
                $img_e = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $t->firma_entrega));
                $tmp_e = sys_get_temp_dir() . '/f_e_traslado_' . $traslado_id . '_' . uniqid() . '.png';
                file_put_contents($tmp_e, $img_e);
                $pdf->Image($tmp_e, $x_inicial + 15, $y_inicial + 8, 50, 20);
                unlink($tmp_e);
            }
            
            // Renderizar Firma de Quien Recibe
            $pdf->SetXY($x_inicial + 82.5, $y_inicial);
            $pdf->Cell(82.5, 8, utf8_decode('FIRMA DE QUIEN RECIBE:'), 0, 1, 'C');
            if (!empty($t->firma_verifica)) {
                $img_v = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $t->firma_verifica));
                $tmp_v = sys_get_temp_dir() . '/f_v_traslado_' . $traslado_id . '_' . uniqid() . '.png';
                file_put_contents($tmp_v, $img_v);
                $pdf->Image($tmp_v, $x_inicial + 82.5 + 15, $y_inicial + 8, 50, 20);
                unlink($tmp_v);
            }
            
            // Reemplazo de las líneas bajas por los Nombres extraídos de la BD
            $pdf->SetY($y_inicial + 30);
            $pdf->SetFont('Arial', '', 10);
            $nom_ent = !empty($t->nombre_entrega) ? $t->nombre_entrega : 'No Especificado';
            $nom_rec = !empty($t->nombre_recibe) ? $t->nombre_recibe : 'No Especificado';
            
            $pdf->Cell(82.5, 8, utf8_decode('Entregado por: ' . $nom_ent), 'T', 0, 'C');
            $pdf->Cell(82.5, 8, utf8_decode('Recibido por: ' . $nom_rec), 'T', 1, 'C');

            invfacil_registrar_bitacora("Visualizó el PDF del Traslado #TR-$traslado_id (De '$n_origen' a '$n_destino')");

            $pdf->Output('I', 'Traslado_'.$traslado_id.'.pdf');
            exit;
        }
    }
}
add_action('init', 'invfacil_procesar_descarga_pdf_traslado');

function invfacil_procesar_exportacion_csv() {
    if ( isset($_GET['exportar_csv_erp']) ) {
        if ( ! is_user_logged_in() ) wp_die('Debe iniciar sesión para descargar.');

        global $wpdb;
        $conteo_id = intval($_GET['exportar_csv_erp']);

        $t_conteos = $wpdb->prefix . 'invfacil_conteos';
        $t_items   = $wpdb->prefix . 'invfacil_conteo_items';
        $t_puntos  = $wpdb->prefix . 'invfacil_puntos';

        $conteo = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_conteos WHERE id = %d", $conteo_id));
        if ( ! $conteo ) wp_die('Inventario no encontrado.');

        $punto = $wpdb->get_row($wpdb->prepare("SELECT nombre_punto FROM $t_puntos WHERE id = %d", $conteo->punto_id));
        $nombre_sede = $punto ? $punto->nombre_punto : 'Sede Desconocida';
        
        $codigo_bodega_erp = substr(trim($nombre_sede), 0, 4);

        $productos = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_items WHERE conteo_id = %d", $conteo_id));

        if (ob_get_length()) { ob_end_clean(); }

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="Inventario_ERP_' . $conteo_id . '.txt"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        foreach ($productos as $p) {
            $codigo_erp = isset($p->codigo) ? trim($p->codigo) : '';
            $cantidad_erp = number_format(floatval($p->cantidad), 3, '.', '');
            $observacion = isset($p->observaciones) ? trim($p->observaciones) : '';
            $observacion = str_replace(array("\r", "\n", "\t"), ' ', $observacion);
            
            $linea = $codigo_bodega_erp . "\t0\t" . $codigo_erp . "\t0\t.\t" . $cantidad_erp . "\t" . $observacion . "\t\r\n";
            fwrite($output, $linea);
        }

        if(function_exists('invfacil_registrar_bitacora')) {
            invfacil_registrar_bitacora("Exportó a TXT (Formato ERP) el Inventario ID: #$conteo_id de la sede $nombre_sede");
        }

        fclose($output);
        exit; 
    }
}
add_action('init', 'invfacil_procesar_exportacion_csv');


function invfacil_procesar_descarga_pdf() {
    if ( isset($_GET['descargar_pdf_azsign']) ) {
        if ( ! is_user_logged_in() ) wp_die('Debe iniciar sesión para descargar.');
        
        global $wpdb;
        $conteo_id = intval($_GET['descargar_pdf_azsign']);
        
        $t_conteos = $wpdb->prefix . 'invfacil_conteos';
        $t_items   = $wpdb->prefix . 'invfacil_conteo_items';
        $t_puntos  = $wpdb->prefix . 'invfacil_puntos';
        
        $conteo = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_conteos WHERE id = %d", $conteo_id));
        if ( ! $conteo ) wp_die('Inventario no encontrado.');
        
        $punto = $wpdb->get_row($wpdb->prepare("SELECT nombre_punto FROM $t_puntos WHERE id = %d", $conteo->punto_id));
        $productos = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_items WHERE conteo_id = %d", $conteo_id));
        
        $verificador = get_userdata($conteo->verificador_id);
        $jefe = get_userdata($conteo->jefe_id);
        
        $nombre_sede = $punto ? $punto->nombre_punto : 'Sede Desconocida';
        $nombre_verificador = $verificador ? $verificador->display_name : 'Usuario Borrado';
        $nombre_jefe = $jefe ? $jefe->display_name : 'No Asignado';

        $ruta_fpdf = plugin_dir_path( __FILE__ ) . 'includes/fpdf/fpdf.php';
        if( file_exists($ruta_fpdf) ) {
            require_once $ruta_fpdf;
            
            if (ob_get_length()) { ob_end_clean(); }
            
            $pdf = new FPDF();
            $pdf->SetMargins(35, 10, 10); 
            $pdf->AddPage();
            
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, utf8_decode('REPORTE OFICIAL DE INVENTARIO FÍSICO'), 0, 1, 'C');
            $pdf->Ln(5);
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 8, utf8_decode('Punto de Atención: ' . $nombre_sede), 0, 1);
            $pdf->Cell(0, 8, utf8_decode('Verificador (Elaboró): ' . $nombre_verificador), 0, 1);
            $pdf->Cell(0, 8, utf8_decode('Jefe de Punto (Revisa): ' . $nombre_jefe), 0, 1);
            $pdf->Cell(0, 8, utf8_decode('Fecha de Levantamiento: ' . date('d/m/Y H:i', strtotime($conteo->fecha_asignacion))), 0, 1);
            $pdf->Ln(10);

            foreach($productos as $p) {
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
            
            if(function_exists('invfacil_registrar_bitacora')) {
                invfacil_registrar_bitacora("Descargó el PDF del Inventario ID: #$conteo_id ($nombre_sede)");
            }

            $pdf->Output('D', 'Inventario_Oficial_' . $conteo_id . '.pdf');
            exit; 
        } else {
            wp_die('Error: No se encontró la librería FPDF.');
        }
    }
}
add_action('init', 'invfacil_procesar_descarga_pdf');

require_once plugin_dir_path( __FILE__ ) . 'admin/admin-inventario.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/admin-puntos.php'; 
require_once plugin_dir_path( __FILE__ ) . 'admin/admin-historico.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/admin-bodegas.php'; 
require_once plugin_dir_path( __FILE__ ) . 'admin/admin-traslados-historico.php'; 
require_once plugin_dir_path( __FILE__ ) . 'admin/admin-bitacora.php'; 
require_once plugin_dir_path( __FILE__ ) . 'includes/azsign-api.php';
require_once plugin_dir_path( __FILE__ ) . 'public/shortcodes.php';

add_action('admin_init', 'invfacil_activar_plugin');

function invfacil_crear_tabla_conversiones() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'invfacil_conversiones';
    
    if ( $wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name ) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            unidad_toma varchar(100) NOT NULL,
            unidad_erp varchar(100) NOT NULL,
            factor decimal(16,8) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
}
add_action('init', 'invfacil_crear_tabla_conversiones');

function invfacil_shortcode_panel_conversiones() {
    if ( ! is_user_logged_in() ) {
        return '<p>Debes iniciar sesión para administrar las conversiones.</p>';
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'invfacil_conversiones';
    $mensaje = '';

    if ( isset($_GET['borrar_conv']) ) {
        $id_borrar = intval($_GET['borrar_conv']);
        $wpdb->delete($table_name, array('id' => $id_borrar));
        $mensaje = '<div style="color: green; font-weight: bold; margin-bottom:15px; padding:10px; background:#e6ffe6; border-left:4px solid green;">Conversión eliminada correctamente.</div>';
    }

    if ( isset($_POST['guardar_conversion_manual']) ) {
        $u_toma = sanitize_text_field($_POST['unidad_toma']);
        $u_erp  = sanitize_text_field($_POST['unidad_erp']);
        $factor = floatval($_POST['factor']);

        if ( !empty($u_toma) && !empty($u_erp) && $factor > 0 ) {
            $wpdb->insert($table_name, array(
                'unidad_toma' => strtoupper(trim($u_toma)),
                'unidad_erp'  => strtoupper(trim($u_erp)),
                'factor'      => $factor
            ));
            $mensaje = '<div style="color: green; font-weight: bold; margin-bottom:15px; padding:10px; background:#e6ffe6; border-left:4px solid green;">Conversión manual guardada con éxito.</div>';
        } else {
            $mensaje = '<div style="color: red; font-weight: bold; margin-bottom:15px; padding:10px; background:#ffe6e6; border-left:4px solid red;">Error: Verifica que los campos no estén vacíos y el factor sea válido.</div>';
        }
    }

    if ( isset($_POST['subir_csv_conversiones']) && !empty($_FILES['archivo_csv']['tmp_name']) ) {
        $archivo = $_FILES['archivo_csv']['tmp_name'];
        if ( ($handle = fopen($archivo, "r")) !== FALSE ) {
            $fila = 0;
            $wpdb->query("START TRANSACTION"); 
            while ( ($datos = fgetcsv($handle, 1000, ";")) !== FALSE ) { 
                $fila++;
                if ($fila == 1) continue; 
                
                if ( count($datos) >= 3 ) {
                    $u_toma = sanitize_text_field(trim($datos[0]));
                    $u_erp  = sanitize_text_field(trim($datos[1]));
                    $factor = floatval(str_replace(',', '.', trim($datos[2])));

                    if ( !empty($u_toma) && !empty($u_erp) && $factor > 0 ) {
                        $wpdb->insert($table_name, array(
                            'unidad_toma' => strtoupper($u_toma),
                            'unidad_erp'  => strtoupper($u_erp),
                            'factor'      => $factor
                        ));
                    }
                }
            }
            fclose($handle);
            $wpdb->query("COMMIT");
            $mensaje = '<div style="color: green; font-weight: bold; margin-bottom:15px; padding:10px; background:#e6ffe6; border-left:4px solid green;">Carga masiva completada. Se procesaron '.($fila-1).' filas.</div>';
        } else {
             $mensaje = '<div style="color: red; font-weight: bold; margin-bottom:15px;">Error al leer el archivo CSV.</div>';
        }
    }

    $conversiones = $wpdb->get_results("SELECT * FROM $table_name ORDER BY unidad_erp ASC, unidad_toma ASC");

    ob_start();
    ?>
    <div class="invfacil-panel" style="background:#f9f9f9; padding:20px; border-radius:8px; border:1px solid #ddd; font-family:sans-serif;">
        <h2 style="margin-top:0; color:#135e96;">⚙️ Panel de Conversiones (Diccionario de Unidades)</h2>
        <?php echo $mensaje; ?>

        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:30px;">
            <div style="flex:1; min-width:300px; background:#fff; padding:20px; border:1px solid #ccc; border-radius:5px;">
                <h3 style="margin-top:0;">➕ Agregar Manualmente</h3>
                <form method="POST">
                    <label><b>Unidad de Toma</b> (Ej: GRAMO (Frasco 500g)):</label><br>
                    <input type="text" name="unidad_toma" required style="width:100%; margin-top:5px; margin-bottom:15px; padding:8px;"><br>
                    
                    <label><b>Unidad ERP</b> (Destino. Ej: UNIDAD):</label><br>
                    <input type="text" name="unidad_erp" required style="width:100%; margin-top:5px; margin-bottom:15px; padding:8px;"><br>
                    
                    <label><b>Factor de Conversión</b> (Ej: 0.002):</label><br>
                    <input type="number" step="0.00000001" name="factor" required style="width:100%; margin-top:5px; margin-bottom:15px; padding:8px;"><br>
                    
                    <button type="submit" name="guardar_conversion_manual" style="background:#135e96; color:#fff; padding:10px 15px; border:none; border-radius:4px; cursor:pointer; width:100%; font-weight:bold;">Guardar Conversión</button>
                </form>
            </div>

            <div style="flex:1; min-width:300px; background:#fff; padding:20px; border:1px solid #ccc; border-radius:5px;">
                <h3 style="margin-top:0;">📁 Carga Masiva (CSV)</h3>
                <p style="font-size:14px; color:#555;">Sube un archivo de Excel guardado como <b>CSV (delimitado por comas)</b>. Debe tener 3 columnas exactas y una fila de encabezados que se ignorará.</p>
                <div style="background:#eee; padding:10px; border-radius:4px; font-family:monospace; font-size:12px; margin-bottom:15px;">
                    <b>Columna A:</b> Unidad Toma<br>
                    <b>Columna B:</b> Unidad ERP<br>
                    <b>Columna C:</b> Factor<br>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="file" name="archivo_csv" accept=".csv" required style="margin-bottom:20px; width:100%;"><br>
                    <button type="submit" name="subir_csv_conversiones" style="background:#28a745; color:#fff; padding:10px 15px; border:none; border-radius:4px; cursor:pointer; width:100%; font-weight:bold;">Subir Archivo CSV</button>
                </form>
            </div>
        </div>

        <h3 style="color:#135e96;">📋 Diccionario Actual en el Sistema</h3>
        <div style="overflow-x:auto; border:1px solid #ccc; border-radius:5px;">
            <table style="width:100%; border-collapse:collapse; background:#fff; text-align:left; font-size:14px;">
                <thead>
                    <tr style="background:#135e96; color:#fff;">
                        <th style="padding:12px; border-bottom:1px solid #ccc;">Unidad Toma</th>
                        <th style="padding:12px; border-bottom:1px solid #ccc;">Unidad ERP (Base)</th>
                        <th style="padding:12px; border-bottom:1px solid #ccc;">Factor de Multiplicación</th>
                        <th style="padding:12px; border-bottom:1px solid #ccc; text-align:center;">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($conversiones)): ?>
                        <tr><td colspan="4" style="padding:15px; text-align:center; color:#777;">No hay conversiones registradas en el diccionario.</td></tr>
                    <?php else: ?>
                        <?php foreach($conversiones as $c): ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:10px; font-weight:bold;"><?php echo esc_html($c->unidad_toma); ?></td>
                                <td style="padding:10px; color:#135e96;"><?php echo esc_html($c->unidad_erp); ?></td>
                                <td style="padding:10px; font-family:monospace;"><?php echo esc_html($c->factor); ?></td>
                                <td style="padding:10px; text-align:center;">
                                    <a href="?borrar_conv=<?php echo $c->id; ?>" style="color:#dc3545; text-decoration:none; font-weight:bold; font-size:12px; padding:4px 8px; border:1px solid #dc3545; border-radius:3px;" onclick="return confirm('¿Seguro que deseas borrar esta conversión?');">Eliminar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('panel_conversiones', 'invfacil_shortcode_panel_conversiones');

function invfacil_generar_opciones_unidad($unidad_erp_esperada, $unidad_seleccionada = '') {
    global $wpdb;
    $tabla_conv = $wpdb->prefix . 'invfacil_conversiones';
    
    $unidad_erp = strtoupper(trim($unidad_erp_esperada));
    if ( empty($unidad_erp) ) { $unidad_erp = 'UNIDAD'; }

    $conversiones = $wpdb->get_results($wpdb->prepare(
        "SELECT unidad_toma FROM $tabla_conv WHERE unidad_erp = %s ORDER BY unidad_toma ASC",
        $unidad_erp
    ));

    $html_opciones = '';
    
    $marcar = function($u) use ($unidad_seleccionada) {
        return (strtoupper(trim($u)) === strtoupper(trim($unidad_seleccionada))) ? 'selected' : '';
    };

    if ( empty($conversiones) ) {
        $html_opciones .= '<option value="' . esc_attr($unidad_erp) . '" ' . $marcar($unidad_erp) . '>' . esc_html($unidad_erp) . '</option>';
    } else {
        $html_opciones .= '<option value="' . esc_attr($unidad_erp) . '" ' . $marcar($unidad_erp) . '>' . esc_html($unidad_erp) . '</option>';
        foreach ($conversiones as $conv) {
            if (strtoupper(trim($conv->unidad_toma)) === $unidad_erp) continue; 
            $html_opciones .= '<option value="' . esc_attr($conv->unidad_toma) . '" ' . $marcar($conv->unidad_toma) . '>' . esc_html($conv->unidad_toma) . '</option>';
        }
    }
    
    if (!empty($unidad_seleccionada) && strpos($html_opciones, 'selected') === false) {
        if (strtoupper($unidad_seleccionada) !== 'UNIDAD' && strtoupper($unidad_seleccionada) !== 'UN') {
            $html_opciones = '<option value="' . esc_attr($unidad_seleccionada) . '" selected>' . esc_html($unidad_seleccionada) . '</option>' . $html_opciones;
        }
    }
    
    return $html_opciones;
}

function invfacil_interceptor_conversiones() {
    if ( isset($_POST['invfacil_enviar_firma']) && isset($_POST['conteo_id']) ) {
        global $wpdb;
        $t_items = $wpdb->prefix . 'invfacil_conteo_items';
        $t_conv  = $wpdb->prefix . 'invfacil_conversiones';

        if ( !empty($_POST['maleta_json']) ) {
            $datos_desempaquetados = json_decode(stripslashes($_POST['maleta_json']), true);
            if (is_array($datos_desempaquetados)) {
                $_POST['items'] = $datos_desempaquetados;
            }
            unset($_POST['maleta_json']); 
        }

        if ( isset($_POST['items']) && is_array($_POST['items']) ) {
            foreach ( $_POST['items'] as $item_id => $datos ) {
                
                $cant_contada_raw = preg_replace('/[^\d.,-]/', '', sanitize_text_field($datos['cantidad']));
                $cant_contada = floatval(str_replace(',', '.', $cant_contada_raw));
                $unidad_contada = strtoupper(trim(sanitize_text_field($datos['unidad_conteo'])));

                $item_db = $wpdb->get_row($wpdb->prepare("SELECT cantidad_esperada, unidad_sistema FROM $t_items WHERE id = %d", intval($item_id)));
                
                if ($item_db) {
                    $unidad_esperada = strtoupper(trim($item_db->unidad_sistema));
                    if (empty($unidad_esperada)) $unidad_esperada = 'UNIDAD';
                    $cantidad_esperada = floatval($item_db->cantidad_esperada);

                    if ($unidad_contada !== $unidad_esperada) {
                        $factor = $wpdb->get_var($wpdb->prepare(
                            "SELECT factor FROM $t_conv WHERE unidad_toma = %s AND unidad_erp = %s LIMIT 1",
                            $unidad_contada, $unidad_esperada
                        ));

                        if ($factor) {
                            $cantidad_calculada = $cant_contada * floatval($factor);

                            $diferencia = abs($cantidad_calculada - $cantidad_esperada);
                            if ($diferencia > 0 && $diferencia <= 0.3) { 
                                $cantidad_calculada = $cantidad_esperada; 
                            }

                            $_POST['items'][$item_id]['cantidad'] = number_format($cantidad_calculada, 3, '.', '');
                            $_POST['items'][$item_id]['unidad_conteo'] = $unidad_esperada;
                            
                            $obs_actual = isset($_POST['items'][$item_id]['observaciones']) ? $_POST['items'][$item_id]['observaciones'] : '';
                            $nota_auto = "[Auto-Conversión: Físicamente se contó " . $cant_contada . " " . $unidad_contada . "]";
                            $_POST['items'][$item_id]['observaciones'] = empty(trim($obs_actual)) ? $nota_auto : $obs_actual . " | " . $nota_auto;
                        }
                    } else {
                        $diferencia = abs($cant_contada - $cantidad_esperada);
                        if ($diferencia > 0 && $diferencia <= 0.3) {
                            $_POST['items'][$item_id]['cantidad'] = number_format($cantidad_esperada, 3, '.', '');
                        }
                    }
                }
            }
        }
    }
}
add_action('wp_loaded', 'invfacil_interceptor_conversiones');

// ========================================================================
// OPTIMIZACIÓN: CARGAR CSS EXTERNO Y VARIABLES PARA JS
// ========================================================================
function invfacil_cargar_recursos() {
    // Cargamos el nuevo archivo CSS optimizado
    wp_enqueue_style('invfacil-main-styles', plugin_dir_url(__FILE__) . 'assets/css/invfacil-styles.css', array(), '5.0');
    
    // Le pasamos a Javascript la URL oficial de AJAX de WordPress para enviar los errores
    wp_localize_script('jquery', 'invfacil_ajax', array(
        'url' => admin_url('admin-ajax.php')
    ));
}
add_action('wp_enqueue_scripts', 'invfacil_cargar_recursos');
add_action('admin_enqueue_scripts', 'invfacil_cargar_recursos');

// ========================================================================
// RECEPTOR DE ERRORES JAVASCRIPT (LOG)
// ========================================================================
add_action('wp_ajax_invfacil_log_js_error', 'invfacil_guardar_error_js');
add_action('wp_ajax_nopriv_invfacil_log_js_error', 'invfacil_guardar_error_js');

function invfacil_guardar_error_js() {
    if (isset($_POST['error_msg'])) {
        $mensaje = sanitize_text_field($_POST['error_msg']);
        $agente = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : 'Desconocido';
        
        $log_file = plugin_dir_path(__FILE__) . 'js-errores.log';
        $fecha = date('Y-m-d H:i:s');
        
        // Escribimos el error en el archivo físico
        file_put_contents($log_file, "[$fecha] FRONTEND JS ERROR: $mensaje | Dispositivo: $agente\n", FILE_APPEND);
    }
    wp_die(); 
}
// ========================================================================
// DESCARGA DE ACTA CON DOBLE FIRMA DIGITAL EN PARALELO
// ========================================================================
function invfacil_procesar_descarga_pdf_recepcion() {
    if (isset($_GET['descargar_pdf_recepcion']) && is_user_logged_in()) {
        global $wpdb;
        $recepcion_id = intval($_GET['descargar_pdf_recepcion']);
        $t_rec = $wpdb->prefix . 'invfacil_recepciones';
        $t_rec_it = $wpdb->prefix . 'invfacil_recepcion_items';

        $rec = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_rec WHERE id = %d", $recepcion_id));
        if($rec) {
            
            // 🚀 LIMPIEZA DE BUFFER: Previene el error "Some data has already been output"
            if (ob_get_length()) { ob_end_clean(); }

            require_once plugin_dir_path( __FILE__ ) . 'includes/fpdf/fpdf.php';
            $pdf = new FPDF('P', 'mm', 'A4');
            $pdf->SetMargins(15, 15, 15); 
            $pdf->AddPage();
            
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, utf8_decode('ACTA DE RECEPCIÓN DE SOLICITUDES VARIAS (TRASLADO)'), 1, 1, 'C');
            $pdf->Ln(5);
            $pdf->SetFont('Arial', '', 10);
            // Le asignamos el ancho total (190) a las bodegas y forzamos el salto de línea con el '1'
            $pdf->Cell(190, 8, utf8_decode('Bodega Origen: ' . $rec->bodega_origen), 1, 1, 'L');
            $pdf->Cell(190, 8, utf8_decode('Bodega Destino: ' . $rec->bodega_destino), 1, 1, 'L');
            // Mantenemos Número y Fecha compartiendo el ancho (95 cada uno)
            $pdf->Cell(95, 8, utf8_decode('Número (Número ERP): ' . $rec->nume_erp), 1, 0, 'L');
            $pdf->Cell(95, 8, utf8_decode('Fecha Recepción: ' . date('d/m/Y H:i', strtotime($rec->fecha_recepcion))), 1, 1, 'L');
            
            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(25, 8, 'CODIGO', 1, 0, 'C');
            $pdf->Cell(110, 8, 'PRODUCTO', 1, 0, 'C');
            $pdf->Cell(25, 8, 'UND.', 1, 0, 'C');
            $pdf->Cell(30, 8, 'ENTREGADO', 1, 1, 'C');

            $pdf->SetFont('Arial', '', 9);
            $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_rec_it WHERE recepcion_id = %d", $recepcion_id));
            foreach($items as $p) {
                $pdf->Cell(25, 8, utf8_decode($p->codigo), 1, 0, 'C');
                $nombre_corto = strlen($p->nombre) > 60 ? substr($p->nombre, 0, 60) . '...' : $p->nombre;
                $pdf->Cell(110, 8, utf8_decode($nombre_corto), 1, 0, 'L');
                $pdf->Cell(25, 8, utf8_decode($p->unidad), 1, 0, 'C');
                $pdf->Cell(30, 8, $p->cant_entregada, 1, 1, 'C');
            }

            $pdf->Ln(15);
            
            $x_inicial = $pdf->GetX();
            $y_inicial = $pdf->GetY();
            
            // 1. Renderizar Firma de Quien Entrega
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(90, 8, utf8_decode('FIRMA DE QUIEN ENTREGA:'), 0, 0, 'L');
            if (!empty($rec->firma_entrega)) {
                $img_e = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $rec->firma_entrega));
                $tmp_e = sys_get_temp_dir() . '/f_e_' . $recepcion_id . '_' . uniqid() . '.png';
                file_put_contents($tmp_e, $img_e);
                $pdf->Image($tmp_e, $x_inicial, $y_inicial + 8, 55, 22);
                unlink($tmp_e);
            }
            
            // 2. Renderizar Firma de Quien Verifica / Recibe
            $pdf->SetXY($x_inicial + 100, $y_inicial);
            $pdf->Cell(90, 8, utf8_decode('FIRMA DE QUIEN VERIFICA / RECIBE:'), 0, 1, 'L');
            if (!empty($rec->firma_verifica)) {
                $img_v = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $rec->firma_verifica));
                $tmp_v = sys_get_temp_dir() . '/f_v_' . $recepcion_id . '_' . uniqid() . '.png';
                file_put_contents($tmp_v, $img_v);
                $pdf->Image($tmp_v, $x_inicial + 100, $y_inicial + 8, 55, 22);
                unlink($tmp_v);
            }
            
            $pdf->SetY($y_inicial + 35);
            $user_entregador = get_userdata($rec->entregador_id);
            $nombre_entregador = $user_entregador ? $user_entregador->display_name : 'No Asignado';
            $nombre_receptor = !empty($rec->nombre_recibe) ? $rec->nombre_recibe : 'No Especificado';

            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(90, 8, utf8_decode('Entregado por: ' . $nombre_entregador), 'T', 0, 'C');
            $pdf->Cell(10, 8, '', 0, 0);
            $pdf->Cell(90, 8, utf8_decode('Recibido por: ' . $nombre_receptor), 'T', 1, 'C');

            $pdf->Output('I', 'Recepcion_ERP_'.$rec->nume_erp.'.pdf');
            exit;
        }
    }
}
add_action('init', 'invfacil_procesar_descarga_pdf_recepcion');
