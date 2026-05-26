<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Función para enviar el PDF a AZSign
 * Basado en LibIntegracionAZSign.php proporcionado por el usuario
 */
function invfacil_enviar_azsign($pdf_base64, $email_verificador, $nombre_verificador, $email_jefe, $nombre_jefe, $nombre_punto) {
    
    // Credenciales AZSign (Debes reemplazarlas con tus datos reales)
    $url = 'https://azsign.analitica.com.co/WebServices/SOAP/';
    $AplicativoId = '20210929-190600-7c4a62-16212874'; 
    $AplicativoPassword = '6bc57b6176d84be5be61019c3fd560b8';
    $CuentaId = '20210701-105606-67dcc4-33117705';
    $GrupoId = '20211113-201323-110403-83001590';

    $fecha_actual = date('Y-m-d H:i:s');
    $nombre_documento = "Inventario_" . sanitize_title($nombre_punto) . ".pdf";

    // Construcción del XML SOAP basado en Ejemplo_Acuerdo-SOAP.xml
    $xml = '<?xml version="1.0" encoding="UTF-8"?>
    <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
    <soap:Header/>
    <soap:Body>
        <azs:Acuerdo xmlns:azs="http://www.analitica.com.co/AZSign/Esquemas" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
            Cuenta="'.$CuentaId.'" Grupo="'.$GrupoId.'" Aplicativo="'.$AplicativoId.'" 
            Nombre="Inventario Físico - '.$nombre_punto.'" TipoFirma="E" Estado="1-P">
            
            <GruposPartcipantes>
                <Grupo Nombre="Verificador" Orden="0" Rol="F">
                    <Participante Email="'.$email_verificador.'" Nombre="'.$nombre_verificador.'"/>
                </Grupo>
                <Grupo Nombre="Jefe de Punto" Orden="1" Rol="F">
                    <Participante Email="'.$email_jefe.'" Nombre="'.$nombre_jefe.'"/>
                </Grupo>
            </GruposPartcipantes>
            
            <Mensaje>Por favor, revise y firme el inventario físico realizado el '.$fecha_actual.' para el punto '.$nombre_punto.'.</Mensaje>
            
            <Documentos>
                <Documento Nombre="'.$nombre_documento.'" TipoMime="application/pdf">'.$pdf_base64.'</Documento>
            </Documentos>
            
        </azs:Acuerdo>
    </soap:Body>
    </soap:Envelope>';

    // Ejecutar cURL (Lógica extraída de LibIntegracionAZSign.php)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Solo para desarrollo, en prod cambiar a true
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml', 'SOAPAction: urn:#Acuerdo'));
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_USERPWD, "$AplicativoId:$AplicativoPassword");

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array(
        'code' => $httpCode,
        'response' => $result
    );
}

// Función para ir a buscar y descargar el PDF firmado desde AZSign
function invfacil_obtener_pdf_azsign($documento_id) {
    // ⚠️ IMPORTANTE: Asegúrate de poner tus credenciales reales (Cuenta y Aplicativo)
    $url = 'https://azsign.analitica.com.co/WebServices/SOAP/';
    $AplicativoId = '20210929-190600-7c4a62-16212874'; 
    $AplicativoPassword = '6bc57b6176d84be5be61019c3fd560b8';
    $CuentaId = '20210701-105606-67dcc4-33117705';

    // Limpiamos espacios accidentales
    $documento_id = trim($documento_id);

    // XML estricto: AZSign SOLO acepta Cuenta, Aplicativo y DocumentoId aquí. NO agregar Grupo.
    $xml = '<?xml version="1.0" encoding="UTF-8"?>
    <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
      <soap:Body>
        <azs:SolicitarDocumento xmlns:azs="http://www.analitica.com.co/AZSign/Esquemas" 
            Cuenta="'.$CuentaId.'" Aplicativo="'.$AplicativoId.'" DocumentoId="'.$documento_id.'"/>
      </soap:Body>
    </soap:Envelope>';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 
    
    // Cabeceras estrictas
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: text/xml; charset=utf-8',
        'SOAPAction: "urn:#SolicitarDocumento"',
        'Expect:' 
    ));
    
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_USERPWD, "$AplicativoId:$AplicativoPassword");

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Extraer el PDF en Base64 ignorando namespaces
    if (preg_match('/<[^:]*:?DocumentoPDF_Base64[^>]*>(.*?)<\/[^:]*:?DocumentoPDF_Base64>/is', $result, $matches)) {
        return array('exito' => true, 'pdf' => $matches[1]);
    }

    // Retornamos la respuesta cruda si falla
    return array('exito' => false, 'error_raw' => "CÓDIGO HTTP: $http_code\n\nRESPUESTA CRUDA:\n" . $result);
}