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
$fase_actual = $temp3['data'] + 1;
# Clase principal 
$debug = true;
$hint = new Hint($ani, $dnid, $agi, $debug);

$varchivo = $agi->get_variable("ARCHIVO");
$vduracion = $agi->get_variable("REC_DUR");

$archivo = $varchivo['data'];
$duracion = $vduracion['data'];
$ns = $duracion * 1000;
$res_g = $agi->record_file($archivo,'wav',1,$ns,0,FALSE,10);

?>