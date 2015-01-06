#!/usr/bin/php -q 
<?php 
# Configuracion de PHP
ini_set("display_errors","0");
ini_set("html_errors","0");
ini_set("error_log", "/var/log/php_error.log");
ini_set("error_reporting", 6143);
ob_implicit_flush();

# Librerias asociadas
require("/opt/AtIVR/lib/hintclass.php");

# Clase principal 
$hint = new Hint(0,0,0, true);
$etiqueta = "50366.1417141739";
$intento = 1;
//$m = $hint->memcache->get($etiqueta);
//$n = $hint->memcache->get('resultado.'.$etiqueta);

//$hint->debug($m);
//$hint->debug($n[0]['fecha']);
//$res = $hint->check_resultados($etiqueta, $intento);
//echo $res."\n";

//echo date("Y-m-d H:i:s+u");
//echo "\n";

?>