<?php
/**
 * Plugin Name: Inventario y Traslados Fácil
 * Description: Control de inventarios y traslados con integración AZSign y Bitácora de Auditoría.
 * Version: 5.0
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
    
    // Obtener IP (incluso si está detrás de un proxy)
    $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];
    // Obtener fecha y hora local de WordPress
    $fecha_hora = current_time('mysql'); 
    
    // Verificamos que la tabla exista para evitar errores si el plugin no se ha reactivado
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

    // 1. INTENTO DE CREACIÓN NORMAL CON DBDELTA
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

    // ========================================================================
    // 2. SISTEMA DE AUTO-REPARACIÓN (Bypass de seguridad para producción)
    // ========================================================================
    
    // Validar si la tabla conteos existe y reparar columna 'intento' si falta
    if($wpdb->get_var("SHOW TABLES LIKE '$t_conteos'") == $t_conteos) {
        $col_intento = $wpdb->get_row("SHOW COLUMNS FROM $t_conteos LIKE 'intento'");
        if ( ! $col_intento ) {
            $wpdb->query("ALTER TABLE $t_conteos ADD intento int(11) DEFAULT 1 NOT NULL;");
        }
    }

    // Validar si la tabla items existe y reparar columnas del doble ciego si faltan
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
}

// 2. Registrar los menús del Backend
// ========================================================================
// 2. CONFIGURACIÓN DE MENÚS Y ACCESOS SEGÚN ROL
// ========================================================================
function invfacil_menu_admin() {
    $usuario = wp_get_current_user();
    $roles = (array) $usuario->roles;

    // A. Menús para Administradores del Plugin, Auditores y Super Administradores de WP
    if ( array_intersect( ['administrator', 'invfacil_admin', 'invfacil_auditor'], $roles ) ) {
        
        // Módulo de Inventarios
        add_menu_page( 'Inventario Fácil', 'Inventarios', 'read', 'invfacil-admin', 'invfacil_pantalla_admin', 'dashicons-clipboard', 6 );
        add_submenu_page( 'invfacil-admin', 'Puntos de Atención', 'Puntos de Atención', 'read', 'invfacil-puntos', 'invfacil_pantalla_puntos' );
        add_submenu_page( 'invfacil-admin', 'Histórico Inventarios', 'Histórico', 'read', 'invfacil-historico', 'invfacil_pantalla_historico' );
        
        // Módulo de Traslados
        add_menu_page( 'Traslados ERP', 'Traslados ERP', 'read', 'invfacil-traslados-hist', 'invfacil_pantalla_traslados_historico', 'dashicons-migrate', 7 );
        add_submenu_page( 'invfacil-traslados-hist', 'Bodegas y Productos', 'Configuración Traslados', 'read', 'invfacil-bodegas', 'invfacil_pantalla_bodegas' );
    }

    // B. Menú Exclusivo para Auditores (y Super Administradores)
    if ( array_intersect( ['administrator', 'invfacil_auditor'], $roles ) ) {
        add_submenu_page( 'invfacil-admin', 'Bitácora de Auditoría', '🕵️ Bitácora (Auditor)', 'read', 'invfacil-bitacora', 'invfacil_pantalla_bitacora' );
    }
}
add_action( 'admin_menu', 'invfacil_menu_admin' );

// ========================================================================
// ESCUDO DE SEGURIDAD: BLOQUEAR BACKEND A OPERATIVOS
// ========================================================================
function invfacil_bloquear_backend_operativos() {
    // Si estamos en el panel de control y no es una petición en segundo plano (AJAX)
    if ( is_admin() && ! wp_doing_ajax() ) {
        $usuario = wp_get_current_user();
        $roles = (array) $usuario->roles;
        
        // Si el usuario es Verificador o Jefe de Punto...
        if ( in_array( 'invfacil_verificador', $roles ) || in_array( 'invfacil_jefe', $roles ) ) {
            // Lo redirigimos a la página principal del sitio (Frontend)
            wp_redirect( home_url() );
            exit;
        }
    }
}
add_action( 'admin_init', 'invfacil_bloquear_backend_operativos' );
// ========================================================================
// DESCARGAS DEL MÓDULO DE TRASLADOS (PDF y CSV)
// ========================================================================

// Función 1: Generar el archivo CSV para Excel
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
            $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tabla_items WHERE traslado_id = %d", $traslado_id));

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

            // Registro en la Bitácora
            invfacil_registrar_bitacora("Exportó a Excel el Traslado #TR-$traslado_id (De '$n_origen' a '$n_destino')");

            fclose($output);
            exit;
        }
    }
}
add_action('init', 'invfacil_procesar_exportacion_csv_traslado');

// Función 2: Visualizar el PDF al vuelo
function invfacil_procesar_descarga_pdf_traslado() {
    if (isset($_GET['descargar_pdf_traslado']) && current_user_can('manage_options')) {
        global $wpdb;
        $traslado_id     = intval($_GET['descargar_pdf_traslado']);
        $tabla_traslados = $wpdb->prefix . 'invfacil_traslados';
        $tabla_bodegas   = $wpdb->prefix . 'invfacil_bodegas';
        $tabla_items     = $wpdb->prefix . 'invfacil_traslado_items';

        $t = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabla_traslados WHERE id = %d", $traslado_id));
        if($t) {
            
            require_once plugin_dir_path( __FILE__ ) . 'includes/fpdf/fpdf.php';
            $pdf = new FPDF('P', 'mm', 'A4');
            $pdf->SetMargins(35, 10, 10); 
            $pdf->AddPage();
            
            $n_origen = $wpdb->get_var($wpdb->prepare("SELECT nombre_bodega FROM $tabla_bodegas WHERE id = %d", $t->bodega_origen_id));
            $n_destino = $wpdb->get_var($wpdb->prepare("SELECT nombre_bodega FROM $tabla_bodegas WHERE id = %d", $t->bodega_destino_id));
            $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tabla_items WHERE traslado_id = %d", $traslado_id));

            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(40, 15, 'CLUB MILITAR', 1, 0, 'C');
            $pdf->Cell(100, 15, 'REPORTE TRASLADO DE INVENTARIOS', 1, 0, 'C');
            $pdf->SetFont('Arial', '', 8);
            $pdf->MultiCell(50, 5, utf8_decode("CÓDIGO: AB-P10-F02\nVERSION: 1\nFECHA: 25/01/2022"), 1, 'L');
            
            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(30, 8, 'MOTIVO:', 1, 0, 'C');
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(110, 8, utf8_decode('Traslado Interno'), 1, 0, 'L');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(20, 8, 'FECHA:', 1, 0, 'C');
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(30, 8, date('d/m/Y', strtotime($t->fecha_traslado)), 1, 1, 'C');

            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(190, 8, 'MOVIMIENTO DE SALIDA', 1, 1, 'C');
            $pdf->Cell(95, 8, utf8_decode($t->tipo_salida), 1, 0, 'C');
            $pdf->Cell(95, 8, utf8_decode($t->tipo_entrada), 1, 1, 'C');
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(95, 8, utf8_decode($n_origen), 1, 0, 'C');
            $pdf->Cell(95, 8, utf8_decode($n_destino), 1, 1, 'C');

            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(25, 8, 'CODIGO', 1, 0, 'C');
            $pdf->Cell(85, 8, 'PRODUCTO', 1, 0, 'C');
            $pdf->Cell(20, 8, 'CANT.', 1, 0, 'C');
            $pdf->Cell(30, 8, 'UND.MEDIDA', 1, 0, 'C');
            $pdf->Cell(30, 8, 'TOTAL', 1, 1, 'C');

            $pdf->SetFont('Arial', '', 8);
            foreach($items as $p) {
                $pdf->Cell(25, 8, utf8_decode($p->producto_codigo), 1, 0, 'C');
                $nombre_corto = strlen($p->producto_nombre) > 45 ? substr($p->producto_nombre, 0, 45) . '...' : $p->producto_nombre;
                $pdf->Cell(85, 8, utf8_decode($nombre_corto), 1, 0, 'L');
                $pdf->Cell(20, 8, $p->cantidad, 1, 0, 'C');
                $pdf->Cell(30, 8, utf8_decode($p->unidad_medida), 1, 0, 'C');
                $pdf->Cell(30, 8, '', 1, 1, 'C');
            }

            $pdf->Ln(15);
            $pdf->Cell(95, 8, '_________________________________', 0, 0, 'C');
            $pdf->Cell(95, 8, '_________________________________', 0, 1, 'C');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(95, 8, 'NOMBRE DE QUIEN ENTREGA (FIRMA)', 0, 0, 'C');
            $pdf->Cell(95, 8, 'NOMBRE DE QUIEN RECIBE (FIRMA)', 0, 1, 'C');

            // Registro en la Bitácora
            invfacil_registrar_bitacora("Visualizó el PDF del Traslado #TR-$traslado_id (De '$n_origen' a '$n_destino')");

            $pdf->Output('I', 'Traslado_'.$traslado_id.'.pdf');
            exit;
        }
    }
}
add_action('init', 'invfacil_procesar_descarga_pdf_traslado');

// ========================================================================
// EXPORTACIÓN A TXT (Formato puro para el ERP - Código de Bodega Dinámico)
// ========================================================================
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
        
        // Extraemos los primeros 4 caracteres del nombre del punto
        $codigo_bodega_erp = substr(trim($nombre_sede), 0, 4);

        $productos = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_items WHERE conteo_id = %d", $conteo_id));

        // 1. LIMPIEZA DE BUFFER (Evita que el archivo se corrompa)
        if (ob_get_length()) { ob_end_clean(); }

        // 2. FORZAR DESCARGA COMO ARCHIVO DE TEXTO PLANO (.txt)
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="Inventario_ERP_' . $conteo_id . '.txt"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // 3. CONSTRUCCIÓN DE LAS FILAS
        foreach ($productos as $p) {
            $codigo_erp = isset($p->codigo) ? trim($p->codigo) : '';
            
            // Forzamos los 3 decimales separados por punto que exige tu ERP (Ej: 12.000)
            $cantidad_erp = number_format(floatval($p->cantidad), 3, '.', '');
            
            // --- INYECCIÓN DE OBSERVACIONES ---
            $observacion = isset($p->observaciones) ? trim($p->observaciones) : '';
            $observacion = str_replace(array("\r", "\n", "\t"), ' ', $observacion);
            
            // Armamos la línea. Notarás que el último tabulador ahora lleva la observación
            $linea = $codigo_bodega_erp . "\t0\t" . $codigo_erp . "\t0\t.\t" . $cantidad_erp . "\t" . $observacion . "\t\r\n";
            
            // Usamos fwrite en lugar de fputcsv para evitar que PHP inyecte comillas
            fwrite($output, $linea);
        }

        if(function_exists('invfacil_registrar_bitacora')) {
            invfacil_registrar_bitacora("Exportó a TXT (Formato ERP) el Inventario ID: #$conteo_id de la sede $nombre_sede");
        }

        fclose($output);
        exit; // Detiene PHP
    }
}
// Conectamos la función al frontend original
add_action('init', 'invfacil_procesar_exportacion_csv');


// ========================================================================
// DESCARGA DE PDF OFICIAL (Frontend Seguro)
// ========================================================================
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
        
        /* * NOTA: Si cuentas con una función propia para descargar el PDF ya firmado 
         * directamente desde AZSign, puedes invocarla aquí usando $conteo->azsign_acuerdo_id. 
         * De lo contrario, este código regenera el PDF oficial en tiempo real.
         */
        
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
            
            // Limpieza de buffer para evitar error de archivo corrupto o pantalla blanca
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

            // Forzamos la descarga en el navegador con la letra 'D'
            $pdf->Output('D', 'Inventario_Oficial_' . $conteo_id . '.pdf');
            exit; // Detenemos PHP para que entregue solo el archivo
        } else {
            wp_die('Error: No se encontró la librería FPDF.');
        }
    }
}
// ¡Clave! El gancho "init" asegura que se intercepte la orden en el frontend
add_action('init', 'invfacil_procesar_descarga_pdf');


// 5. Incluir archivos de vistas y lógica
require_once plugin_dir_path( __FILE__ ) . 'admin/admin-inventario.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/admin-puntos.php'; 
require_once plugin_dir_path( __FILE__ ) . 'admin/admin-historico.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/admin-bodegas.php'; 
require_once plugin_dir_path( __FILE__ ) . 'admin/admin-traslados-historico.php'; 
require_once plugin_dir_path( __FILE__ ) . 'admin/admin-bitacora.php'; // <-- NUEVA VISTA
require_once plugin_dir_path( __FILE__ ) . 'includes/azsign-api.php';
require_once plugin_dir_path( __FILE__ ) . 'public/shortcodes.php';

add_action('admin_init', 'invfacil_activar_plugin');

// ========================================================================
// MÓDULO DE CONVERSIONES: 1. CREACIÓN DE TABLA OCULTA
// ========================================================================
function invfacil_crear_tabla_conversiones() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'invfacil_conversiones';
    
    // Si la tabla no existe, la creamos con alta precisión para los decimales (16,8)
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
// Se ejecuta silenciosamente para asegurar que el diccionario exista
add_action('init', 'invfacil_crear_tabla_conversiones');

// ========================================================================
// MÓDULO DE CONVERSIONES: 2. SHORTCODE PANEL ADMINISTRADOR
// ========================================================================
function invfacil_shortcode_panel_conversiones() {
    // Seguridad básica
    if ( ! is_user_logged_in() ) {
        return '<p>Debes iniciar sesión para administrar las conversiones.</p>';
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'invfacil_conversiones';
    $mensaje = '';

    // -- A. PROCESAR BORRADO --
    if ( isset($_GET['borrar_conv']) ) {
        $id_borrar = intval($_GET['borrar_conv']);
        $wpdb->delete($table_name, array('id' => $id_borrar));
        $mensaje = '<div style="color: green; font-weight: bold; margin-bottom:15px; padding:10px; background:#e6ffe6; border-left:4px solid green;">Conversión eliminada correctamente.</div>';
    }

    // -- B. PROCESAR INGRESO MANUAL --
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

    // -- C. PROCESAR CARGA MASIVA (CSV) --
    if ( isset($_POST['subir_csv_conversiones']) && !empty($_FILES['archivo_csv']['tmp_name']) ) {
        $archivo = $_FILES['archivo_csv']['tmp_name'];
        if ( ($handle = fopen($archivo, "r")) !== FALSE ) {
            $fila = 0;
            // Usamos una transacción para que guarde miles de registros en un milisegundo
            $wpdb->query("START TRANSACTION"); 
            while ( ($datos = fgetcsv($handle, 1000, ";")) !== FALSE ) { 
                $fila++;
                if ($fila == 1) continue; // Saltamos la cabecera del Excel
                
                if ( count($datos) >= 3 ) {
                    $u_toma = sanitize_text_field(trim($datos[0]));
                    $u_erp  = sanitize_text_field(trim($datos[1]));
                    // Soporte para factores latinos (con coma) o americanos (con punto)
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

    // -- D. OBTENER DICCIONARIO ACTUAL --
    $conversiones = $wpdb->get_results("SELECT * FROM $table_name ORDER BY unidad_erp ASC, unidad_toma ASC");

    // -- E. RENDERIZAR PANEL INTERFAZ --
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

// ========================================================================
// MÓDULO DE CONVERSIONES: 3. HELPER PARA EL MENÚ DESPLEGABLE (VERIFICADOR)
// ========================================================================
function invfacil_generar_opciones_unidad($unidad_erp_esperada, $unidad_seleccionada = '') {
    global $wpdb;
    $tabla_conv = $wpdb->prefix . 'invfacil_conversiones';
    
    // 1. Unidad que espera el ERP
    $unidad_erp = strtoupper(trim($unidad_erp_esperada));
    if ( empty($unidad_erp) ) { $unidad_erp = 'UNIDAD'; }

    // 2. Buscamos conversiones disponibles para esta base
    $conversiones = $wpdb->get_results($wpdb->prepare(
        "SELECT unidad_toma FROM $tabla_conv WHERE unidad_erp = %s ORDER BY unidad_toma ASC",
        $unidad_erp
    ));

    $html_opciones = '';
    
    // Función rápida para marcar la opción que el usuario ya había elegido
    $marcar = function($u) use ($unidad_seleccionada) {
        return (strtoupper(trim($u)) === strtoupper(trim($unidad_seleccionada))) ? 'selected' : '';
    };

    if ( empty($conversiones) ) {
        // MODO CLÁSICO ESTRICTO: Solo la unidad del ERP
        $html_opciones .= '<option value="' . esc_attr($unidad_erp) . '" ' . $marcar($unidad_erp) . '>' . esc_html($unidad_erp) . '</option>';
    } else {
        // MODO INTELIGENTE ESTRICTO: La del ERP + las opciones del Panel Admin
        $html_opciones .= '<option value="' . esc_attr($unidad_erp) . '" ' . $marcar($unidad_erp) . '>' . esc_html($unidad_erp) . '</option>';
        foreach ($conversiones as $conv) {
            if (strtoupper(trim($conv->unidad_toma)) === $unidad_erp) continue; 
            $html_opciones .= '<option value="' . esc_attr($conv->unidad_toma) . '" ' . $marcar($conv->unidad_toma) . '>' . esc_html($conv->unidad_toma) . '</option>';
        }
    }
    
    // FILTRO DE LIMPIEZA: Si la unidad vieja era "Unidad" o "UN" por defecto del sistema, la ignoramos.
    if (!empty($unidad_seleccionada) && strpos($html_opciones, 'selected') === false) {
        if (strtoupper($unidad_seleccionada) !== 'UNIDAD' && strtoupper($unidad_seleccionada) !== 'UN') {
            $html_opciones = '<option value="' . esc_attr($unidad_seleccionada) . '" selected>' . esc_html($unidad_seleccionada) . '</option>' . $html_opciones;
        }
    }
    
    return $html_opciones;
}

// ========================================================================
// MÓDULO DE CONVERSIONES: 4. EL INTERCEPTOR NINJA (MATEMÁTICA Y TOLERANCIA)
// ========================================================================
function invfacil_interceptor_conversiones() {
    // 1. Solo actuamos si el verificador acaba de oprimir el botón "Guardar / Verificar"
    if ( isset($_POST['invfacil_enviar_firma']) && isset($_POST['conteo_id']) ) {
        global $wpdb;
        $t_items = $wpdb->prefix . 'invfacil_conteo_items';
        $t_conv  = $wpdb->prefix . 'invfacil_conversiones';

        // 2. ¡CRUCIAL! Desempaquetamos la maleta JS (Paracaídas) nosotros primero.
        if ( !empty($_POST['maleta_json']) ) {
            $datos_desempaquetados = json_decode(stripslashes($_POST['maleta_json']), true);
            if (is_array($datos_desempaquetados)) {
                $_POST['items'] = $datos_desempaquetados;
            }
            // Destruimos la maleta original para que tu código viejo no la vuelva a desempaquetar
            unset($_POST['maleta_json']); 
        }

        // 3. Revisamos producto por producto lo que mandó el verificador
        if ( isset($_POST['items']) && is_array($_POST['items']) ) {
            foreach ( $_POST['items'] as $item_id => $datos ) {
                
                // Limpiamos la cantidad que digitó el usuario
                $cant_contada_raw = preg_replace('/[^\d.,-]/', '', sanitize_text_field($datos['cantidad']));
                $cant_contada = floatval(str_replace(',', '.', $cant_contada_raw));
                $unidad_contada = strtoupper(trim(sanitize_text_field($datos['unidad_conteo'])));

                // Consultamos a la base de datos qué esperaba realmente el ERP
                $item_db = $wpdb->get_row($wpdb->prepare("SELECT cantidad_esperada, unidad_sistema FROM $t_items WHERE id = %d", intval($item_id)));
                
                if ($item_db) {
                    $unidad_esperada = strtoupper(trim($item_db->unidad_sistema));
                    if (empty($unidad_esperada)) $unidad_esperada = 'UNIDAD';
                    $cantidad_esperada = floatval($item_db->cantidad_esperada);

                    // A. ¿NECESITA CONVERSIÓN? (La unidad del usuario es distinta a la del ERP)
                    if ($unidad_contada !== $unidad_esperada) {
                        
                        // Buscamos el factor mágico que tú configuraste en el panel
                        $factor = $wpdb->get_var($wpdb->prepare(
                            "SELECT factor FROM $t_conv WHERE unidad_toma = %s AND unidad_erp = %s LIMIT 1",
                            $unidad_contada, $unidad_esperada
                        ));

                        if ($factor) {
                            // LA REGLA DE ORO: Multiplicamos
                            $cantidad_calculada = $cant_contada * floatval($factor);

                            // LA TOLERANCIA (±0.03): Si la diferencia es minúscula, lo cuadramos a la perfección
                            $diferencia = abs($cantidad_calculada - $cantidad_esperada);
                            if ($diferencia > 0 && $diferencia <= 0.3) { 
                                $cantidad_calculada = $cantidad_esperada; 
                            }

                            // MUTAMOS EL PAQUETE: Cambiamos los datos engañando a tu sistema viejo
                            $_POST['items'][$item_id]['cantidad'] = number_format($cantidad_calculada, 3, '.', '');
                            $_POST['items'][$item_id]['unidad_conteo'] = $unidad_esperada;
                            
                            // Inyectamos el rastro transparente para el PDF
                            $obs_actual = isset($_POST['items'][$item_id]['observaciones']) ? $_POST['items'][$item_id]['observaciones'] : '';
                            $nota_auto = "[Auto-Conversión: Físicamente se contó " . $cant_contada . " " . $unidad_contada . "]";
                            $_POST['items'][$item_id]['observaciones'] = empty(trim($obs_actual)) ? $nota_auto : $obs_actual . " | " . $nota_auto;
                        }
                    } 
                    // B. NO HUBO CONVERSIÓN, PERO APLICAMOS TOLERANCIA POR SI ACASO (Ej: pesaron en gramos directamente y falló por 0.01)
                    else {
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
// Conectamos al interceptor antes de que WP cargue las pantallas (Ganándole en velocidad al shortcode)
add_action('wp_loaded', 'invfacil_interceptor_conversiones');
