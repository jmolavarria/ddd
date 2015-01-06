#!/usr/bin/php -q 
<?php 
# Configuracion de PHP
ini_set("display_errors","0");
ini_set("html_errors","0");
ini_set("error_log", "/var/log/php_error.log");
ini_set("error_reporting", 6143);
ob_implicit_flush();

# Librerias asociadas
require("/srv/AtIVR/lib/hintclass.php");
require("/srv/AtIVR/lib/phpagi/phpagi.php");
	
# INICIO PROGRAMA
$agi = new AGI();
               
# Variables	
$ani = $agi->request['agi_callerid'];
$dnid = $agi->request['agi_extension'];
$canal = $agi->request['agi_channel'];
$uniqueid = explode(".", $agi->request['agi_uniqueid']);
# Obtener intento
$temp1 = $agi->get_variable('N_INTENTO');
$nintento = $temp1['data'];
# Obtener Tarea
$temp2 = $agi->get_variable('TAREA');
$tarea = (string) $temp2['data'];

# Clase principal
$debug = true;
$hint = new Hint($ani, $dnid, $agi, $debug);

# Buscar resultado en memcache
$datos = $hint->memcache->get("resultado.".$tarea);
if ($datos == FALSE) {
	# Error buscando datos de resultado
	$hint->debug("ERROR EN FASE DE SETUP BUSCANDO RESULTADOS EN MEMCACHE - HANGUP");
	$hint->debug($tarea);
	$agi->hangup();
}
$hint->debug($datos);
# Actualizar con uniqueid y con estado a llamada en curso  ( 1 )
$array_resultado = array();
$array_resultado[0]['fecha'] = time();
$array_resultado[0]['uniqueid'] = $uniqueid[0];
$array_resultado[0]['estado'] = 1;
$array_resultado[0]['tarea_actual'] = 0;
$array_resultado[0]['n_intento'] = $nintento;
$array_resultado[0]['dt'] = $datos[0]['dt'];
$agi->set_variable("FASE_ACTUAL", 0);

$f = $hint->memcache->set("resultado.".$tarea, $array_resultado);
$hint->debug($hint->memcache->getResultCode());
if ($f == FALSE) {
	$hint->debug("ERROR SETEANDO MEMCACHE");
}

//$ndatos = $hint->memcache->get("resultado.".$tarea);
//$hint->debug($ndatos);
?>
