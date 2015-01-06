#!/bin/php -q
<?php
/*
	Runs like executable.php --obj-id=50363 --monitor-id=1051  Adicionalmente podemos agregar --debug para activar el debug
*/

# Configuracion de PHP
ini_set("display_errors","0");
ini_set("html_errors","0");
ini_set("error_log", "/var/log/php_error.log");
ini_set("error_reporting", 6143);
ob_implicit_flush();
$debug = false;

# Librerias
require("/srv/AtIVR/lib/hintclass.php");
# Setup de debug
if ($argv[3]) {
	if (strtolower($argv[3]) == "--debug") {
		$debug = true;
	}
}
$hint = new Hint(0,0,0,$debug);

# Validar conexion con PG
if ($hint->db == false) {
	$hint->debug("SIN ACCESO A LA BASE DE DATOS");
	# Generamos salida
	$monid = explode("=", $argv[2]);
	$objid = explode("=", $argv[1]);
	$error = array();
	$error[0]['mon_id'] = $monid[1];
	$error[0]['starttime'] = time();
	$error[0]['fecha'] = time();
	$error[0]['obj_id'] = $objid[1];
	$error[0]['uniqueid'] = 0;
	$error[0]['resultado_fase'] = 500;
	$error[0]['intento'] = 0;
	$hint->salida_prematura($error);
	exit;
}

# Validar conexion con memcached
/* Deberiamos validar tambien el estado de memcache, es decir si hay espacio */
if ($hint->memcache == false) {
	$hint->debug("SIN ACCESO A MEMORIA");
	# Generamos salida
	$monid = explode("=", $argv[2]);
	$objid = explode("=", $argv[1]);
	$error = array();
	$error[0]['mon_id'] = $monid[1];
	$error[0]['starttime'] = time();
	$error[0]['fecha'] = time();
	$error[0]['obj_id'] = $objid[1];
	$error[0]['uniqueid'] = 0;
	$error[0]['resultado_fase'] = 500;
	$error[0]['intento'] = 0;
	$hint->salida_prematura($error);
	exit;
}

# Validar parametros
$parametros = $hint->check_params($argv[1], $argv[2]);

if ($parametros == false) {
	$hint->debug("PARAMETROS CON ERROR");
	# Generamos salida
	$monid = explode("=", $argv[2]);
	$objid = explode("=", $argv[1]);
	$error = array();
	$error[0]['mon_id'] = $monid[1];
	$error[0]['starttime'] = time();
	$error[0]['fecha'] = time();
	$error[0]['obj_id'] = $objid[1];
	$error[0]['uniqueid'] = 0;
	$error[0]['resultado_fase'] = 400;
	$error[0]['intento'] = 0;
	$hint->salida_prematura($error);
	exit; // with errors!!
}
# Obtener XML segun datos obtenidos
$sxml = $hint->get_xml();
if ($sxml == false) {
	$hint->debug("PARAMETROS INVOCADOS NO EXISTEN EN SISTEMA");
	# Generamos salida
	$monid = explode("=", $argv[2]);
	$objid = explode("=", $argv[1]);
	$error = array();
	$error[0]['mon_id'] = $monid[1];
	$error[0]['starttime'] = time();
	$error[0]['fecha'] = time();
	$error[0]['obj_id'] = $objid[1];
	$error[0]['uniqueid'] = 0;
	$error[0]['resultado_fase'] = 503;
	$error[0]['intento'] = 0;
	$hint->salida_prematura($error);
	exit; // with errors!!
}

# Validar el XML obtenido
$xml_string = <<<XML
<?xml version='1.0'?>
$sxml
XML;
$xml = simplexml_load_string($xml_string);
$hint->xml = $xml;
$etiqueta_id = $hint->validar_xml();
if ($etiqueta_id == false) {
	$hint->debug("EL XML NO PASO LA VALIDACION");
	# Generamos salida
	$monid = explode("=", $argv[2]);
	$objid = explode("=", $argv[1]);
	$error = array();
	$error[0]['mon_id'] = $monid[1];
	$error[0]['starttime'] = time();
	$error[0]['fecha'] = time();
	$error[0]['obj_id'] = $objid[1];
	$error[0]['uniqueid'] = 0;
	$error[0]['resultado_fase'] = 501;
	$error[0]['intento'] = 0;
	$hint->salida_prematura($error);
	exit; // with errors!!
}
$hint->debug($etiqueta_id);

$tarea = $hint->memcache->get($etiqueta_id);
$hint->debug($tarea);

$hint->debug($etiqueta_id);
/*
	ESTADOS DE LLAMADA
	0 LLAMADA GENERADA
	1 LLAMADA EN CURSO
	2 LLAMADA FINALIZADA
	3 ANALISIS EN CURSO
	4 ANALISIS EXITOSO
	5 ANALISIS DEFECTUOSO
*/
$continuar = 1;
$qintento = 1;
$aux_time = 0;
$anormal = 0;

while ($continuar) {
	$hint->debug("AUX TIME: $aux_time");
	$result = $hint->memcache->get("resultado.".$etiqueta_id);
	if($result) {
		$hint->debug("OBTENIENDO RESULTADOS PARCIALES");
		$hint->debug($result);
		$estado = $result[0]['estado'];
		$nintento = $result[0]['n_intento'];
		$cfase = $result[0]['tarea_actual'];
		$hint->debug("Estado Actual: ".$estado);
		$hint->debug("Fase Actual: ".$cfase);
		$hint->debug("Intento Actual: ".$nintento);
	}

	# Generar la llamada solo si el estado actual es 0 (Llamada Generada)
	if (!$result) {
		if ($qintento > $hint->ativr_reintentos) {
			# Salir del ciclo
			$hint->debug("MAXIMO DE INTENTOS ALCANZADOS");
			$aux_time = $hint->ativr_dial_waittime;
			/*
			unset($continuar);
			$anormal = 1;
			break;
			*/
		}else {
			$hint->debug("SE INICIA FASE DE LLAMADA");
			$llamada = $hint->set_call($etiqueta_id, $qintento, $tarea);
			$estado = 0;
		}
	}
	/*
	if ($qintento > $hint->ativr_reintentos) {
		# Salir del ciclo
		$hint->debug("MAXIMO DE INTENTOS ALCANZADOS");
		unset($continuar);
		$anormal = 1;
		break;
	}
	*/
	# Generar salida si transcurre mas tiempo del permitido y estado = 0
	if (($aux_time >= $hint->ativr_dial_waittime)&&($estado == 0)) {
		$hint->debug("FRACASO DE LLAMADA, NO HUBO ANSWER O RUTA CON PROBLEMAS  $aux_time --- {$hint->ativr_dial_waittime}");
		# Ver si se permiten mas intentos
		if ($qintento <= $hint->ativr_reintentos) {
			# Generar intento en BBDD
			$monid = $hint->monitor_id;
			$objid = $hint->servicio_id;
			$error = array();
			$error[0]['mon_id'] = $monid;
			$error[0]['starttime'] = time();
			$error[0]['fecha'] = time();
			$error[0]['obj_id'] = $objid;
			$error[0]['uniqueid'] = 0;
			$error[0]['resultado_fase'] = 601;
			$error[0]['intento'] = $qintento;
			$lastcall = $hint->salida_prematura($error, $etiqueta_id);
			$qintento++;
			$hint->memcache->delete("resultado.".$etiqueta_id);
			unset($llamada[0]['archivo']);
			$aux_time = 0;
		} else {
			# Salir del ciclo
			$hint->debug("CANTIDAD DE INTENTOS DE CONEXION MAXIMA ALCANZADA - SALIDA");
			
			# Generar resultado para output
			unset($continuar);
			$anormal = 1;
			break;
		}
	}
	if ($estado == 2) {
		$hint->debug("LLAMADA FINALIZADA, REALIZANDO ANALISIS");
		$estado = 3;
	}
	while ($estado == 3) {
		$hint->debug("EJECUTANDO ANALISIS");
		sleep(3);
		unset($result);
		$result = $hint->memcache->get("resultado.".$etiqueta_id);
		$estado = $result[0]['estado'];
	}
	if ($estado == 4) {
		$hint->debug("ANALISIS COMPLETADO");
		unset($continuar);
		break;
	}
	if ($estado == 5) {
		# Ver si se permiten mas intentos
		if ($qintento <= $hint->ativr_reintentos) {	
			/* ACA FALTA INSERTAR UN REGISTRO PARA EL RESULTADO FINAL */
			$qintento++;
			$hint->memcache->delete("resultado.".$etiqueta_id);
			$aux_time = 0;
			$hint->debug("AGENDANDO NUEVO INTENTO");
		} else {
			# Salir del ciclo
			# Aca falta insertar otro registro por fallo
			$hint->debug("NO ES POSIBLE AGENDAR NUEVO INTENTO - ESTA LINEA NO DEBERIA EJECUTARSE");
			unset($continuar);
			$anormal = 1;
			break;
		}	
	}
	if ($estado == 0) {
		$aux_time = $aux_time + 2;
	}
	sleep(2);
	unset($result);
}
unset($result);
if ($anormal > 0 ) {
	echo $lastcall;
} else {
	$result = $hint->memcache->get("resultado.".$etiqueta_id);
	echo $result[0]['evaluacion'];
}
$hint->debug("FIN MONITOREO");

?>