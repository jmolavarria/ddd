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
# Obtener fase actual
$temp3 = $agi->get_variable('FASE_ACTUAL');
$fase_actual = $temp3['data'];
# Clase principal 
$debug = true;
$hint = new Hint($ani, $dnid, $agi, $debug);

$proceso = $hint->set_resultados($tarea, $nintento);
if ($proceso == FALSE) {
	$hint->debug("ERROR AL PROCESAR, TAREA NO ENCONTRADA");
}
$ctask = $hint->memcache->get($tarea);
$resultados = $hint->check_resultados($tarea, $nintento);

/*
	Dependiendo del resultado debemos colocar lo siguiente: 
	estado 4 Cuando sea el ultimo intento o cuando la medicion este correcta
	estado 5 si y solo si el paso es visible y no es el ultimo paso
*/
$chk_res = explode("|", $resultados);
$setestado = 4;
$hint->debug($chk_res);
foreach ($chk_res as $k => $v) {
	if ($v != 0) {
		$hint->debug("MEDICION PARCIAL DISTINTA DE LA ESPERADA");
		if ($k == 0) {
		} else {
			if ($ctask[$k]['visible'] == 1) {
				if ($nintento < $ctask[0]['ativr_reintentos']) {
					$hint->debug("ESTE PASO $k REQUIERE REEVALUACION");
					$setestado = 5;								
				}
			}			
		}
	}
}
$datos = $hint->memcache->get("resultado.".$tarea);
# Realmacenar lo que ya esta almacenado
$array_resultado = array();
foreach ($datos as $key => $value) {
	if ($key == 0) {
		$array_resultado[0]['fecha'] = $datos[0]['fecha'];
		$array_resultado[0]['uniqueid'] = $datos[0]['uniqueid'];
		$array_resultado[0]['estado'] = $setestado;
		$array_resultado[0]['tarea_actual'] = $fase_actual;
		$array_resultado[0]['n_intento'] = $datos[0]['n_intento'];
		$array_resultado[0]['dt'] = $datos[0]['dt'];
		$array_resultado[0]['evaluacion'] = $resultados;
	} else {
		$array_resultado[$key]['timestamp'] = $value['timestamp'];
		$array_resultado[$key]['archivo'] = $value['archivo'];
		$array_resultado[$key]['duracion'] = $value['duracion'];
		$array_resultado[$key]['procesado'] = $value['procesado'];
		$array_resultado[$key]['silencio_inicial'] = $value['silencio_inicial'];
		$array_resultado[$key]['silencio_final'] = $value['silencio_final'];
		$array_resultado[$key]['fase'] = $value['fase'];
		$array_resultado[$key]['timestamp_proceso'] = $value['timestamp_proceso'];
		$array_resultado[$key]['spec_file'] = $value['spec_file'];
		$array_resultado[$key]['spec_hd'] = $value['spec_hd'];
		$array_resultado[$key]['soundwave_hd'] = $value['soundwave_hd'];
		$array_resultado[$key]['isok'] = $value['isok'];
	}
}

$f = $hint->memcache->set("resultado.".$tarea, $array_resultado);
$hint->debug($hint->memcache->getResultCode());
if ($f == FALSE) {
	$hint->debug("ERROR SETEANDO MEMCACHE");
}
$hint->debug("FIN DE PROCESAMIENTO");
?>