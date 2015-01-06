<?php
class Hint {
    
    public $ani;
    public $dnid;
    public $agi;
    public $debugging;
	
	/**
	*  
	* Constructor.
	* @Parametros: El ANI, el DNID y la clase AGI
	* @Ejemplo: $test = new Hint(0,0,0);
	*/

	function __construct($ani, $dnid, $agi, $debugging = false) {
		# descriptores de conexion a bbdd
		$this->debug = $debugging;
		$this->totaltime = time();
		$this->db = 0;
		$this->memcache = NULL;
		$this->conectar_bbdd();
		$this->conectar_memcache();
		$this->obj_id = 0;
		$this->monitor_id = 0;
		$this->servicio_id = 0;
		$this->current_date = microtime(true);
		$this->xml = NULL;
		$this->dp = "/etc/asterisk/extensions.conf";
		$this->dp_ativr = "/etc/asterisk/dialplan_ativr.conf";
		$this->acceptable_dtmf = array('1','2','3','4','5','6','7','8','9','0','#');
		
		# En Ejecucion
		$this->ativr_dt = NULL;
		$this->ativr_reintentos = NULL;
		$this->ativr_monitor_id = NULL;
		$this->ativr_dial_tech = NULL;
		$this->ativr_dial_ruta = NULL;
		$this->ativr_dial_number = NULL;
		$this->ativr_dial_waittime = NULL;
		$this->ativr_dialplan = NULL;
		$this->ativr_callerid = NULL;
	}
    
    function __destruct() {
	$this->db = NULL;
	unset($this);
    }
        
	/**
	* Utilizamos PDO para conectarnos a la BBDD
	* 
	*/
        
	function conectar_bbdd() {
		try {
			$this->db = new PDO('pgsql:host=localhost; dbname=rwatch; user=rwatch; password=;');
		}
		catch(PDOException $e) {
			$this->debug($e->getMessage());
			$this->db = false;
		}
		return;
	}

	function conectar_memcache() {
		$this->memcache = new Memcached();
		$this->memcache->addServer('localhost', 11211);
		$access = $this->memcache->getVersion();
		if ($access == false) {
			$this->debug("IMPOSIBLE ACCEDER A MEMCACHED");
			$this->memcache = false;
		}
		return;
	}


	function statement($sql, $params) {
		if (!$this->db) {
			return;
		}
		try {
			$sth = $this->db->prepare($sql);
			$sth->execute($params);
		}
		catch(PDOException $e) {
			$this->debug($e->getMessage());	
		}
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		foreach ($result as $row) {
			$retorno[] = $row;
		}
		@$retorno['zCount'] = $sth->rowCount();
		return @$retorno;
	}
       
	function statement_nc($sql, $params) {
		if (!$this->db) {
			return;
		}
		try {
			$sth = $this->db->prepare($sql);
			$sth->execute($params);
		}
		catch(PDOException $e) {
			$this->debug($e->getMessage());	
		}
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		foreach ($result as $row) {
			$retorno[] = $row;
		}
		return @$retorno;
	}

	function statement_sp($sql, $params) {
		if (!$this->db) {
			return;
		}
		try {
			$sth = $this->db->prepare($sql);
			$sth->execute($params);
		}
		catch(PDOException $e) {
			$this->debug($e->getMessage());	
		}
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		foreach ($result as $row) {
			$retorno[] = $row;
		}
		@$retorno['zCount'] = $sth->rowCount();
		@$retorno['insertId'] = $this->db->lastInsertId();
		return @$retorno;
	}
        
	function debug($str) {
		if ($this->debug) {
			$fecha_aux = date("Y-m-d H:i:s");
			$aux = fopen("/srv/log/analisis/debug.txt","a+");
				if (is_array($str)) {
					fwrite($aux, "$fecha_aux\n".print_r($str,1));
				} else {
					fwrite($aux, "$fecha_aux ---> $str\n");
				}
			fclose($aux);
			return;
		}
	}

	 # Limpiar ingresos de variables (anti sql injection, XSS, etc)
	 function cleanInput($input) {
		 $search = array(
	    	'@<script [^>]*?>.*?@si',          // javascript
			'@< [/!]*?[^<>]*?>@si',            // HTML tags
			'@<style [^>]*?>.*?</style>@siU',  // style tags
			'@< ![sS]*?--[ tnr]*>@'            // multi-line
			);
	 
		$output = preg_replace($search, '', $input);
		$output = strip_tags($output);
		$output = stripslashes($output);
		$output = htmlspecialchars($output);
		$output = htmlentities($output);
		return $output;
	}
	
	function sanitize($input) {
		if (is_array($input)) {
			foreach($input as $var => $val) {
				$output[$var] = $this->sanitize($val);
			}
		}else {
			if (get_magic_quotes_gpc()) {
				$input = stripslashes($input);
			}
			$input = $this->cleanInput($input);
			$output = $input;
		}
		return $output;
	}

	function create_dir($objid) {
		if (!$objid) {
			return false;
		}
		$dirs = array('img','wav','debug');
		$time = time();
		$dt = date("Hi", $time);
		$basepath = "/srv/log/analisis/$objid/$dt/";
		
		foreach ($dirs as $key => $value) {
			if (!is_dir($basepath.$value)) {
				$crear = mkdir($basepath.$value, 0764, true);
				if ($crear == true) {
				} else {
					return false;
				}
			}
		}
		$this->ativr_dt = $dt;
		return;
	}
	
	function check_params($param1, $param2) {
		$continuar = 0;
		$p1 = explode("=", $param1);
		$p2 = explode("=", $param2);
		if (($p1[0] == "--obj-id") && ($p2[0] == "--monitor-id")) {
			$continuar = 1;
			$array['obj-id'] = $p1[1];
			$array['monitor-id'] = $p2[1];
		}
		# No fueron validados los parametros
		if ($continuar == 0) {
			return false;
		}
		if ((is_numeric($array['obj-id'])) && (is_numeric($array['monitor-id']))) {
			$this->obj_id = $array['obj-id'];
			$this->monitor_id = $array['monitor-id'];
			return $array;
		} else {
			return false;
		}
	}
	
	function get_xml() {
		$sql = "SELECT servicio_id
				FROM objetivo o
				INNER JOIN objetivo_config oc ON (o.objetivo_id = oc.objetivo_id)
				WHERE o.objetivo_id = :objetivo_id
				AND :monitor_id = any(monitor_id)";
		$params = array(':objetivo_id' => $this->obj_id, ':monitor_id' => $this->monitor_id);
		$datos = $this->statement($sql, $params);
		if ($datos['zCount'] > 0) {
			$this->servicio_id = $datos[0]['servicio_id'];
			# Hay datos
			$sql = "SELECT xml_configuracion
				FROM objetivo_config oc
				INNER JOIN objetivo o ON (o.objetivo_id = oc.objetivo_id)
				WHERE es_ultima_config = TRUE
				AND servicio_id IN (:servicio_id)
				AND :monitor_id = any(monitor_id)
				AND oc.objetivo_id = :objetivo_id";
			$params = array(':objetivo_id' => $this->obj_id, ':monitor_id' => $this->monitor_id, ':servicio_id' => $this->servicio_id);
			$res = $this->statement($sql, $params);
			if ($res['zCount'] > 0) {
				return $res[0]['xml_configuracion'];
			} else {
				return false;
			}
		} else {
			return false;	
		}

	}
	
	function validar_xml(){
		$array_comparaciones = array(0,1,3,4);
		# Primero que el objetivo_id sea el mismo
		$this->debug("Objetivo id invocado: ".$this->obj_id);
		$this->debug("Objetivo id en xml: ".$this->xml->config->ativr['objetivo_id']);
		if ($this->obj_id != $this->xml->config->ativr['objetivo_id']) {
			$this->debug("DIFERENTE OBJETIVO ID");
			return false;
		}
		
		# Cantidad de reintentos 
		$this->ativr_reintentos = (string) $this->xml->config->ativr->setup['reintentos'];
		$this->ativr_monitor_id = (string) $this->xml->config->ativr->setup['monitor_id'];
		if (($this->ativr_reintentos == NULL) || (strlen($this->ativr_reintentos) == 0)) {
			$this->ativr_reintentos = 0;
		}
		$this->debug("Reintentos: ".$this->ativr_reintentos);
		$this->debug("Monitor_id: ".$this->monitor_id);
		$this->debug("Monitor_id: ".$this->ativr_monitor_id);
		/*
		if ($this->monitor_id != $this->ativr_monitor_id) {
			$this->debug("DIFERENTE MONITOR ID");
			return false;
		}
		*/
		# Paso 0: validemos el numero, que sea visible, que paso_id sea marcado y que tanto la tecnologia como la ruta existan
		if ($this->xml->config->ativr->paso[0]['visible'] != 1) {
			$this->debug("PASO 0 DECLARADO NO VISIBLE");
			return false;
		}
		if (strtoupper($this->xml->config->ativr->paso[0]['paso_id']) != "MARCADO") {
			$this->debug("PASO 0 NO DECLARADO COMO MARCADO");
			return false;
		}

		$this->ativr_dial_tech = $this->xml->config->ativr->paso[0]->numero_llamada['tecnologia'];
		$this->ativr_dial_ruta = $this->xml->config->ativr->paso[0]->numero_llamada['ruta'];
		$this->debug($this->ativr_dial_tech."/".$this->ativr_dial_ruta);
		if ((strlen($this->ativr_dial_tech) == 0) || (strlen($this->ativr_dial_ruta) == 0)) {
			$this->debug("PASO 0 NO DECLARADA RUTA DE SALIDA PARA LA LLAMADA");
			return false;
		}
		
		$this->ativr_dialplan = $this->xml->config->ativr->paso[0]->numero_llamada['dialplan'];
		$this->ativr_callerid = $this->xml->config->ativr->paso[0]->numero_llamada['callerid'];
		# Deberiamos buscar este dialplan en asterisk
		$linea = "/usr/sbin/asterisk -rx \"dialplan show ".$this->ativr_dialplan."\"";
		$exec = exec($linea, $res_exec);
		$search_fail = preg_match("/failed/", $exec, $coincidencias);
		if (count($coincidencias) > 0) {
			print_r($coincidencias);
			return false;
		}
		
		$this->ativr_dial_number = (string) $this->xml->config->ativr->paso[0]->numero_llamada['valor'];
		$this->ativr_dial_waittime = $this->xml->config->ativr->paso[0]->setup_paso['timeoutstep'];
		if (($this->ativr_dial_waittime == NULL) || ($this->ativr_dial_waittime < 10)) {
			$this->ativr_dial_waittime  = 10;
		}
		$this->debug("Numero: ".$this->ativr_dial_number);
		$this->debug("WaitTime: ".$this->xml->config->ativr->paso[0]->setup_paso['timeoutstep']);
		if (strlen($this->ativr_dial_number) == 0) {
			$this->debug("PASO 0 NUMERO DECLARADO NO CORRESPONDE");
			return false;
		}
		
		# Ahora a recorrer cada paso posterior que entrega la configuracion
		$qpasos = count($this->xml->config->ativr->paso);
		$array_pasos = array();
		for ($i = 1; $i < $qpasos; $i++) {
			# Pasos 1 a n
			$this->debug("Paso Orden: ".$this->xml->config->ativr->paso[$i]['paso_orden']);
			$paso_orden = (string) $this->xml->config->ativr->paso[$i]['paso_orden'];
			$visible =  (string) $this->xml->config->ativr->paso[$i]['visible'];
			$timeoutstep = (string) $this->xml->config->ativr->paso[$i]->setup_paso['timeoutstep'];
			$waitbeforestep = (string) $this->xml->config->ativr->paso[$i]->setup_paso['waitbeforestep'];
			$waitafterstep = (string) $this->xml->config->ativr->paso[$i]->setup_paso['waitafterstep'];
			$tolerancia_inicial = (string) $this->xml->config->ativr->paso[$i]->setup_paso['tolerancia_inicial'];
			$tolerancia_final = (string) $this->xml->config->ativr->paso[$i]->setup_paso['tolerancia_final'];
			$tipo_comparacion = (string) $this->xml->config->ativr->paso[$i]->setup_paso['tipo_comparacion'];
			# Etiquetas especiales
			if ($tipo_comparacion == 2) {
				$duracion_segmento_primero = (string) $this->xml->config->ativr->paso[$i]->setup_paso['duracion_segmento_primero'];
				$silencio_min_segundo_seg = (string) $this->xml->config->ativr->paso[$i]->setup_paso['silencio_min_segundo_seg'];
				$silencio_max_segundo_seg = (string) $this->xml->config->ativr->paso[$i]->setup_paso['silencio_max_segundo_seg'];
				$duracion_minima_primero = (string) $this->xml->config->ativr->paso[$i]->setup_paso['duracion_minima_primero'];
				$duracion_minima_segundo = (string) $this->xml->config->ativr->paso[$i]->setup_paso['duracion_minima_segundo'];
			}
			if ($tipo_comparacion == 3) {
				$silencio_min = (string) $this->xml->config->ativr->paso[$i]->setup_paso['silencio_min_segundo_seg'];
				$silencio_max = (string) $this->xml->config->ativr->paso[$i]->setup_paso['silencio_max_segundo_seg'];
				$duracion_minima_segundo = (string) $this->xml->config->ativr->paso[$i]->setup_paso['duracion_minima_segundo'];
			}
			if ($tipo_comparacion == 4) {
				$duracion_segmento_primero = (string) $this->xml->config->ativr->paso[$i]->setup_paso['duracion_segmento_primero'];
				$silencio_min_segundo_seg = (string) $this->xml->config->ativr->paso[$i]->setup_paso['silencio_min_segundo_seg'];
				$silencio_max_segundo_seg = (string) $this->xml->config->ativr->paso[$i]->setup_paso['silencio_max_segundo_seg'];
				$duracion_minima_primero = (string) $this->xml->config->ativr->paso[$i]->setup_paso['duracion_minima_primero'];
				$duracion_minima_segundo = (string) $this->xml->config->ativr->paso[$i]->setup_paso['duracion_minima_segundo'];
			}
			$dtmf_delay = (string) $this->xml->config->ativr->paso[$i]->dtmf['delay'];
			if ($dtmf_delay == "") {
				$dtmf_delay = 250;
			}
			$dtmf_valor = (string) $this->xml->config->ativr->paso[$i]->dtmf['valor'];
			$audio_path = (string) $this->xml->config->ativr->paso[$i]->audio['path'];
			$imagen_path = (string) $this->xml->config->ativr->paso[$i]->audio['imagen_path'];
			$audio_duracion = (string) $this->xml->config->ativr->paso[$i]->audio['duracion'];
			
			# Validamos que exista el archivo de imagen y de audio
			if ((!is_file($audio_path))||(!is_file($imagen_path))) {
				$this->debug("AUDIO O IMAGEN NO ENCONTRADA EN EL SETUP");
				return false;
			}
			
			
			$audio_si = (string) $this->xml->config->ativr->paso[$i]->audio['silencio_inicial'];
			$audio_sf = (string) $this->xml->config->ativr->paso[$i]->audio['silencio_final'];
			
			$array_pasos[$i]['paso_orden'] = $paso_orden;
			$array_pasos[$i]['visible'] = $visible;
			$array_pasos[$i]['timeoutstep'] = $timeoutstep;
			$array_pasos[$i]['waitbeforestep'] = $waitbeforestep;
			$array_pasos[$i]['waitafterstep'] = $waitafterstep;
			$array_pasos[$i]['tolerancia_inicial'] = $tolerancia_inicial;
			$array_pasos[$i]['tolerancia_final'] = $tolerancia_final;
			$array_pasos[$i]['tipo_comparacion'] = $tipo_comparacion;
			if ($tipo_comparacion == 2) {
				$array_pasos[$i]['duracion_segmento_primero'] =	$duracion_segmento_primero;
				$array_pasos[$i]['silencio_min_segundo_seg'] =	$silencio_min_segundo_seg;
				$array_pasos[$i]['silencio_max_segundo_seg'] =	$silencio_max_segundo_seg;
				$array_pasos[$i]['duracion_minima_primero'] =	$duracion_minima_primero;
				$array_pasos[$i]['duracion_minima_segundo'] =	$duracion_minima_segundo;
			}
			if ($tipo_comparacion == 3) {
				$array_pasos[$i]['silencio_min'] =	$silencio_min;
				$array_pasos[$i]['silencio_max'] =	$silencio_max;
				$array_pasos[$i]['duracion_minima_segundo'] =	$duracion_minima_segundo;
			}
			if ($tipo_comparacion == 4) {
				$array_pasos[$i]['duracion_segmento_primero'] =	$duracion_segmento_primero;
				$array_pasos[$i]['silencio_min_segundo_seg'] =	$silencio_min_segundo_seg;
				$array_pasos[$i]['silencio_max_segundo_seg'] =	$silencio_max_segundo_seg;
				$array_pasos[$i]['duracion_minima_primero'] =	$duracion_minima_primero;
				$array_pasos[$i]['duracion_minima_segundo'] =	$duracion_minima_segundo;
			}
			$array_pasos[$i]['dtmf_delay'] = $dtmf_delay;
			$array_pasos[$i]['dtmf_valor'] = $dtmf_valor;
			$array_pasos[$i]['audio_path'] = $audio_path;
			$array_pasos[$i]['imagen_path'] = $imagen_path;
			$array_pasos[$i]['audio_duracion'] = $audio_duracion;
			$array_pasos[$i]['audio_si'] = $audio_si;
			$array_pasos[$i]['audio_sf'] = $audio_sf;
			if ($i == ($qpasos -1)) {
				$array_pasos[$i]['fin'] = 1;
			} else {
				$array_pasos[$i]['fin'] = 0;
			}
			
		}

		foreach ($array_pasos as $key => $value) {
			if ($key != $value['paso_orden']) {
				$this->debug("PASO $key NO CORRESPONDE AL DECLARADO");
				return FALSE;
			}
			if (($value['visible'] != '0') && ($value['visible'] != '1')) {
				$this->debug($value['visible']);
				$this->debug("CAMPO VISIBLE EN $key NO DECLARADO");
				return FALSE;				
			}
			if (($value['timeoutstep'] == '0') || ($value['timeoutstep'] == "")) {
				$this->debug($value['timeoutstep']);
				$this->debug("CAMPO timeoutstep EN $key NO DECLARADO");
				return FALSE;				
			}
			if ($value['waitbeforestep'] == "") {
				$this->debug($value['waitbeforestep']);
				$this->debug("CAMPO waitbeforestep EN $key NO DECLARADO");
				return FALSE;				
			}
			if ($value['waitafterstep'] == "") {
				$this->debug($value['waitafterstep']);
				$this->debug("CAMPO waitafterstep EN $key NO DECLARADO");
				return FALSE;				
			}
			if ($value['tolerancia_inicial'] == "") {
				$this->debug($value['tolerancia_inicial']);
				$this->debug("CAMPO tolerancia_inicial EN $key NO DECLARADO");
				return FALSE;				
			}
			if ($value['tolerancia_final'] == "") {
				$this->debug($value['tolerancia_final']);
				$this->debug("CAMPO tolerancia_final EN $key NO DECLARADO");
				return FALSE;				
			}
			if ($value['tipo_comparacion'] == "") {
				$this->debug($value['tipo_comparacion']);
				$this->debug("CAMPO tipo_comparacion EN $key NO DECLARADO");
				return FALSE;				
			}
			if (!in_array($value['tipo_comparacion'], $array_comparaciones)) {
				$this->debug("CAMPO tipo_comparacion EN $key NO EXISTE EN TIPO DE COMPARACIONES ACORDADAS");
				return FALSE;				
			}
			
			# Check que en tipo comparacion 2 y 3 vengan los valores que corresponden
			if($value['tipo_comparacion'] == 2) {
				if (($value['duracion_segmento_primero'] == "")||($value['silencio_min_segundo_seg'] == "")||($value['silencio_max_segundo_seg'] == "")||($value['duracion_minima_primero'] == "")||($value['duracion_minima_segundo'] == "")) {
					$this->debug("CAMPO tipo_comparacion DEFINIDO COMO 2 SIN CAMPOS MINIMOS PARA PROCESAR");
					return FALSE;
				}
			}
			if($value['tipo_comparacion'] == 3) {
				if (($value['silencio_min'] == "")||($value['silencio_max'] == "")||($value['duracion_minima_segundo'] == "")) {
					$this->debug("CAMPO tipo_comparacion DEFINIDO COMO 3 SIN CAMPOS MINIMOS PARA PROCESAR");
					return FALSE;
				}
			}
			if($value['tipo_comparacion'] == 4) {
				if (($value['duracion_segmento_primero'] == "")||($value['silencio_min_segundo_seg'] == "")||($value['silencio_max_segundo_seg'] == "")||($value['duracion_minima_primero'] == "")||($value['duracion_minima_segundo'] == "")) {
					$this->debug("CAMPO tipo_comparacion DEFINIDO COMO 4 SIN CAMPOS MINIMOS PARA PROCESAR");
					return FALSE;
				}
			}
			if ($value['audio_path'] == "") {
				$this->debug($value['audio_path']);
				$this->debug("CAMPO audio_path EN $key NO DECLARADO");
				return FALSE;				
			}
			if ($value['audio_duracion'] == "") {
				$this->debug($value['audio_duracion']);
				$this->debug("CAMPO audio_duracion EN $key NO DECLARADO");
				return FALSE;				
			}
			if ($value['audio_si'] == "") {
				$this->debug($value['audio_si']);
				$this->debug("CAMPO audio_si EN $key NO DECLARADO");
				return FALSE;				
			}
			if ($value['audio_sf'] == "") {
				$this->debug($value['audio_sf']);
				$this->debug("CAMPO audio_sf EN $key NO DECLARADO");
				return FALSE;				
			}
			# Validar los valores del DTMF
			$this->debug($value['dtmf_valor']);
			if (strlen($value['dtmf_valor']) > 0 ) {
				# Revisar que cada valor este permitido
				$this->debug('Largo: '.strlen($value['dtmf_valor']));
				for ($e = 0;$e < strlen($value['dtmf_valor']);$e++) { 
					$this->debug(substr($value['dtmf_valor'],$e,1));
					
					if (!in_array(substr($value['dtmf_valor'],$e,1), $this->acceptable_dtmf)){
						$this->debug($value['dtmf_valor']);
						$this->debug("CAMPO dtmf_valor EN $key NO CORRESPONDE");
						return FALSE;	
					}
				}
			}
		} /* Fin validacion */
		$this->debug("Q: ".count($array_pasos));
		$this->debug($this->ativr_dial_tech);
		$this->debug($this->ativr_dial_ruta);
		$array_pasos[0]['obj_id'] = $this->obj_id;
		$array_pasos[0]['mon_id'] = $this->monitor_id;
		$array_pasos[0]['q_pasos'] = $qpasos;
		$array_pasos[0]['starttime'] = $this->totaltime;
		$array_pasos[0]['ativr_reintentos'] = $this->ativr_reintentos;
		$array_pasos[0]['ativr_dial_tech'] = (string) $this->ativr_dial_tech;
		$array_pasos[0]['ativr_dial_ruta'] = (string) $this->ativr_dial_ruta;
		$array_pasos[0]['ativr_dial_number'] = $this->ativr_dial_number;
		$array_pasos[0]['ativr_dial_waittime'] = (int) $this->ativr_dial_waittime;
		$array_pasos[0]['dialplan'] = (string) $this->ativr_dialplan;
		$array_pasos[0]['callerid'] = (string) $this->ativr_callerid;

		
		# Almacenar en memcache y devolver puntero
		$op = $this->memcache->set($this->obj_id.".".$this->current_date,$array_pasos);
		if ($op == FALSE) {
			$this->debug("ERROR AL ALMACENAR TAREA EN CACHE");
			return FALSE;
		}
		
		return $this->obj_id.".".$this->current_date;
	}
	
	function set_call($etiqueta, $intento, $tarea) {
		$this->debug("Parametros $etiqueta -- $intento -- $tarea");
		//$this->debug($tarea);
		# Generar Registro para procesarlo luego
		$directorio = $this->create_dir($tarea[0]['obj_id']);
		# Generar salida si es falso
		$archivo = "/var/spool/asterisk/outgoing/".$etiqueta.$this->totaltime;
		$array_resultado = array();
		$array_resultado[0]['fecha'] = date('Y-m-d H:i:s');
		$array_resultado[0]['uniqueid'] = NULL;
		$array_resultado[0]['estado'] = 0;
		$array_resultado[0]['tarea_actual'] = 0;
		$array_resultado[0]['n_intento'] = $intento;
		$array_resultado[0]['dt'] = $this->ativr_dt;
		$array_resultado[0]['archivo'] = $archivo;
		$temp = $this->memcache->add("resultado.".$etiqueta, $array_resultado);

		$n = $this->memcache->get("resultado.".$etiqueta);
		$this->debug($n);
		
		$wt = $tarea[0]['ativr_dial_waittime'];
		$callerid = $tarea[0]['callerid'];
		$context = $tarea[0]['dialplan'];
		$canal = $tarea[0]['ativr_dial_tech']."/".$tarea[0]['ativr_dial_ruta']."/".$tarea[0]['ativr_dial_number'];
		/* Borrar */
		//$canal = "SIP/jvalencia";
		//$context = "dp_class_devel";
		/* fin borrar*/
		
		$callfile = <<<EOF
Channel: $canal
Callerid: $callerid
MaxRetries: 0
RetryTime: $wt
WaitTime: $wt
Context: $context
Extension: 100
Priority: 1
Set: TAREA=$etiqueta
Set: N_INTENTO=$intento
EOF;
		# Aqui falta agregar algo mas pro
		$this->debug("Callfile");
		$this->debug($callfile);
		$fp = fopen($archivo, "w");
		fwrite($fp, $callfile);
		fclose($fp);
		return $array_resultado;
	}
	
	function set_resultados($etiqueta, $intento) {
		if (!$etiqueta) {
			$this->debug("VIENE SIN TAREA");
			return false;
		}

		# Obtener la configuracion de tarea
		$tarea = $this->memcache->get($etiqueta);
		if (!$tarea) {
			$this->debug("NO SE PUEDE RESCATAR TAREA DE MEMCACHE");
			return false;
		}
		
		# Obtener los resultados
		$datos = $this->memcache->get("resultado.".$etiqueta);
		if (!$datos) {
			$this->debug("NO SE PUEDE RESCATAR RESULTADOS DE MEMCACHE");
			return false;
		}
		
		# Recorrer resultados
		$array_resultado = array();
		foreach ($datos as $key => $value) {
			if ($key == 0) {
				$array_resultado[0]['fecha'] = $datos[0]['fecha'];
				$array_resultado[0]['uniqueid'] = $datos[0]['uniqueid'];
				$array_resultado[0]['estado'] = 3;
				$array_resultado[0]['tarea_actual'] = $fase_actual;
				$array_resultado[0]['n_intento'] = $datos[0]['n_intento'];
				$array_resultado[0]['dt'] = $datos[0]['dt'];
			} else {
				# Realizar analisis de cada fase
				$nfile = $value['archivo'];
				$nimg = str_replace("wav", "img", $nfile);
				$nimg = str_replace(".img", ".png", $nimg);
				$duracion_audio = $this->audio_duracion($nfile);
				$original_audio_file = $tarea[$key]['audio_path'];
				$original_spectrum_file = $tarea[$key]['imagen_path'];
				$this->debug("ARCHIVOS ORIGINALES");
				$this->debug($original_audio_file);
				$this->debug($original_spectrum_file);
				$this->debug("ARCHIVOS NUEVOS");
				$this->debug($nfile);
				$this->debug($nimg);
				$silences = $this->get_silence($nfile, $duracion_audio);
				$nsi = $silences['si'];
				$nsf = $silences['sf'];
				$this->debug("Silencio Inicial: ".$nsi);
				$this->debug("Silencio Final: ".$nsf);
				/* Modificar siguiente linea cuando el espectrograma venga en el XML */
				$hd = $this->clean_hd($nfile, $nimg, $original_audio_file, $original_spectrum_file);
				$spec_hd = $hd['spec_hd'];
				$audio_hd = $hd['audio_hd'];
				# Agregar resultados
				$array_resultado[$key]['timestamp'] = $value['timestamp'];
				$array_resultado[$key]['archivo'] = $value['archivo'];
				$array_resultado[$key]['duracion'] = $duracion_audio;
				$array_resultado[$key]['procesado'] = 1;
				$array_resultado[$key]['silencio_inicial'] = $nsi;
				$array_resultado[$key]['silencio_final'] = $nsf;
				$array_resultado[$key]['fase'] = $value['fase'];
				$array_resultado[$key]['timestamp_proceso'] = time();
				$array_resultado[$key]['spec_file'] = $nimg;
				$array_resultado[$key]['spec_hd'] = $spec_hd;
				$array_resultado[$key]['soundwave_hd'] = $audio_hd;
				$array_resultado[$key]['isok'] = $value['isok'];
				unset($duracion_audio);
				unset($silences);
				unset($nsi);
				unset($nsf);
				unset($hd);
				unset($spec_hd);
				unset($audio_hd);
			}
		}
		$this->memcache->replace("resultado.".$etiqueta, $array_resultado);
		return true;
	}

	function audio_duracion($audio){
		# Obtener duracion de grabacion
		$cmd = "/usr/local/bin/sox $audio -n stat 2>&1 |  grep \"Length\"|awk -F \":\" '{print $2}'";
		$this->debug($cmd);
		$dur = trim(exec($cmd, $ret));
		$this->debug("Duracion: $dur");
		return $dur;
	}
	
	function clean_hd($nfile, $img_dest, $orig_audio, $orig_img) {
		if (!is_dir("/srv/tmp")) {
			$crear = mkdir("/srv/tmp", 0764, true);
		}
		$file_a = "/srv/tmp/filea_".rand(1,99999).".wav";
		$file_b = "/srv/tmp/fileb_".rand(1,99999).".wav";
		$file_c = "/srv/tmp/filec_".rand(1,99999).".wav";
		$file_d = "/srv/tmp/filed_".rand(1,99999).".wav";
		$file_e = "/srv/tmp/filee_".rand(1,99999).".wav";
		$file_f = "/srv/tmp/filef_".rand(1,99999).".wav";
		$file_g = "/srv/tmp/fileg_".rand(1,99999).".wav";
		$file_h = "/srv/tmp/fileh_".rand(1,99999).".wav";

		# Para nuevos audios
		$linea = "/usr/local/bin/sox $nfile $file_a silence 1 1 1% > /dev/null 2>&1";
		$q = exec($linea, $pp);
		$linea = "/usr/local/bin/sox $file_a $file_b reverse";
		$q = exec($linea, $pp);
		$linea = "/usr/local/bin/sox $file_b $file_c silence 1 1 1% > /dev/null 2>&1";
		$q = exec($linea, $pp);
		$linea = "/usr/local/bin/sox $file_c $file_d reverse";
		$q = exec($linea, $pp);
		$linea = "/usr/local/bin/sox $file_d -n spectrogram -h -a -w Hamming -o $img_dest";
		$this->debug($linea);
		$r = exec($linea, $paso);

		# Para audios originales
		$linea = "/usr/local/bin/sox $orig_audio $file_e silence 1 1 1% > /dev/null 2>&1";
		$q = exec($linea, $pp);
		$linea = "/usr/local/bin/sox $file_e $file_f reverse";
		$q = exec($linea, $pp);
		$linea = "/usr/local/bin/sox $file_f $file_g silence 1 1 1% > /dev/null 2>&1";
		$q = exec($linea, $pp);
		$linea = "/usr/local/bin/sox $file_g $file_h reverse";
		$q = exec($linea, $pp);
		
		/* Ya tenemos generada esta imagen
		$linea = "/usr/local/bin/sox $file_h -n spectrogram -h -a -w Hamming -o $orig_img";
		$this->debug($linea);
		$r = exec($linea, $paso);
		*/
		
		if (file_exists($orig_img)) {
			$imagehash1 = ph_dct_imagehash($orig_img);	
		} else {
			$this->debug("ERROR - ARCHIVO IMG ORIGINAL NO EXISTE");
		}
		
		if (file_exists($file_h)) {
			$audiohash1 = ph_audiohash($file_h);
		} else {
			$this->debug("ERROR - ARCHIVO WAV ORIGINAL NO EXISTE");
		}
		

		
		# Calcular hashes nuevos
		if (file_exists($img_dest)) {
			$imagehash2 = ph_dct_imagehash($img_dest);
		} else {
			$this->debug("ERROR - ARCHIVO IMG NUEVO NO EXISTE");
		}
		if (file_exists($file_d)) {
			$audiohash2 = ph_audiohash($file_d);
		} else {
			$this->debug("ERROR - ARCHIVO WAV NUEVO NO EXISTE");
		}
		
			
		# Image hamming distance
		$spectrum_hd = ph_image_dist($imagehash1, $imagehash2);
		$this->debug("Imagen hd: $spectrum_hd");
		if ($spectrum_hd == ""){
			$spectrum_hd = 100;
		}
		# audio hamming distance
		$sounwave_hd = ph_audio_dist($audiohash1, $audiohash2);
		$this->debug("soundwave hd: $sounwave_hd");
		if ($sounwave_hd == ""){
			$sounwave_hd = 0;
		}
		$retorno = array();
		$retorno['spec_hd'] = $spectrum_hd;
		$retorno['audio_hd'] = $sounwave_hd;
		# Borrado		
		unlink($file_a);
		unlink($file_b);
		unlink($file_c);
		unlink($file_d);
		unlink($file_e);
		unlink($file_f);
		unlink($file_g);
		unlink($file_h);

		return $retorno;
	}

	function caso_especial($caso, $audio, $array) {
		if (!is_dir("/srv/tmp")) {
			$crear = mkdir("/srv/tmp", 0764, true);
		}
		$retorno = 0;
		case '2':
			$file_a = "/srv/tmp/fileza_".rand(1,99999).".wav";
			$file_b = "/srv/tmp/filezb_".rand(1,99999).".wav";
			$duracion_minima_primero = $array['duracion_minima_primero'];
			$duracion_segmento_primero = $array['duracion_segmento_primero'];
			$duracion_segmento_primero_formato = gmdate("i:s", $duracion_segmento_primero);
			$linea = "/usr/local/bin/sox $audio $file_a trim 0 $duracion_segmento_primero_formato > /dev/null 2>&1";
			$q = exec($linea, $pp);
			$dur_pri = audio_duracion($file_a);
			if ($dur_pri < $duracion_minima_primero) {
				$retorno = "13";
				$this->debug("Archivo cortado tiene duracion menor a la minima");
			} else {
				$linea = "/usr/local/bin/sox $file_a $file_b silence 1 1 1% > /dev/null 2>&1";
				$q = exec($linea, $pp);
				$dur_pri_sil = audio_duracion($file_b);
				$tolerancia = $array['tolerancia_inicial'];
				$si = $dur_pri - $dur_pri_sil;
				if ($si > $tolerancia) {
					$retorno = "13";
					$this->debug("Silencio Segmento primero excede de parametros");					
				} else {
					# Ahora tomar segundo segmento
					$dur_total = audio_duracion($audio);
					$dur_total_formato = gmdate("i:s", $dur_total);
					$file_c = "/srv/tmp/filezc_".rand(1,99999).".wav";
					$linea = "/usr/local/bin/sox $audio $file_c trim $duracion_segmento_primero_formato $dur_total_formato > /dev/null 2>&1";
					$q = exec($linea, $pp);
					$dur_seg = audio_duracion($file_c);
					$duracion_minima_segundo = $array['duracion_minima_segundo'];
					if ($dur_seg < $duracion_minima_segundo) {
						$retorno = "13";
						$this->debug("Archivo cortado tiene duracion menor a la minima");
					} else {
						# ahora Validar los silencios
						$file_d = "/srv/tmp/filezd_".rand(1,99999).".wav";
						$linea = "/usr/local/bin/sox $file_c $file_d silence 1 1 1% > /dev/null 2>&1";
						$q = exec($linea, $pp);
						$dur_seg_sil = audio_duracion($file_d);
						$sil_diff = $dur_seg - $dur_seg_sil;
						$sil_min = $array['silencio_min_segundo_seg'];
						$sil_max = $array['silencio_max_segundo_seg'];
						if (($sil_diff >= $sil_min)&&($sil_diff <= $sil_max)){
							# Correcto							
						} else {
							$retorno = "13";
							$this->debug("SILENCIO SEGUNDO SEGMENTO FUERA DE LIMITES");							
						}
						
					}
				}
				
			}
			unlink($file_a);
			unlink($file_b);
			unlink($file_c);
			unlink($file_d);
		break;

		case '3':
			$file_a = "/srv/tmp/fileza_".rand(1,99999).".wav";
			$dur_seg = audio_duracion($audio);
			$dur_minima = $array['duracion_minima_segundo'];
			if ($dur_seg < $duracion_minima) {
				$retorno = "13";
				$this->debug("Archivo tiene duracion menor a la minima");
			} else {
				$linea = "/usr/local/bin/sox $audio $file_a silence 1 1 1% > /dev/null 2>&1";
				$q = exec($linea, $pp);
				$dur_seg_sil = audio_duracion($file_a);
				$sil_diff = $dur_seg - $dur_seg_sil;
				$sil_min = $array['silencio_min_segundo_seg'];
				$sil_max = $array['silencio_max_segundo_seg'];
				if (($sil_diff >= $sil_min)&&($sil_diff <= $sil_max)){
					# Correcto							
				} else {
					$retorno = "13";
					$this->debug("SILENCIO SEGMENTO FUERA DE LIMITES");							
				}
			}
		break;

		case '4':
			$file_a = "/srv/tmp/fileza_".rand(1,99999).".wav";
			$file_b = "/srv/tmp/filezb_".rand(1,99999).".wav";
			$duracion_minima_primero = $array['duracion_minima_primero'];
			$duracion_segmento_primero = $array['duracion_segmento_primero'];
			$duracion_segmento_primero_formato = gmdate("i:s", $duracion_segmento_primero);
			$linea = "/usr/local/bin/sox $audio $file_a trim 0 $duracion_segmento_primero_formato > /dev/null 2>&1";
			$q = exec($linea, $pp);
			$dur_pri = audio_duracion($file_a);
			if ($dur_pri < $duracion_minima_primero) {
				$retorno = "13";
				$this->debug("Archivo cortado tiene duracion menor a la minima");
			} else {
				$linea = "/usr/local/bin/sox $file_a $file_b silence 1 1 1% > /dev/null 2>&1";
				$q = exec($linea, $pp);
				$dur_pri_sil = audio_duracion($file_b);
				$tolerancia = $array['tolerancia_inicial'];
				$si = $dur_pri - $dur_pri_sil;
				if ($si > $tolerancia) {
					$retorno = "13";
					$this->debug("Silencio Segmento primero excede de parametros");					
				} else {
					# Ahora tomar segundo segmento
					$dur_total = audio_duracion($audio);
					$dur_total_formato = gmdate("i:s", $dur_total);
					$file_c = "/srv/tmp/filezc_".rand(1,99999).".wav";
					$linea = "/usr/local/bin/sox $audio $file_c trim $duracion_segmento_primero_formato $dur_total_formato > /dev/null 2>&1";
					$q = exec($linea, $pp);
					$dur_seg = audio_duracion($file_c);
					$duracion_minima_segundo = $array['duracion_minima_segundo'];
					if ($dur_seg < $duracion_minima_segundo) {
						$retorno = "13";
						$this->debug("Archivo cortado tiene duracion menor a la minima");
					} else {
						# ahora buscar audio dentro de este
						$file_d = "/srv/tmp/filezd_".rand(1,99999).".png";
						$data = clean_hd($file_c, $file_d, $array['archivo_original'], $array['imagen_original']);

						$swhd = $data['audio_hd'];
						$imghd = $data['spec_hd'];

						if ($swhd <= 0) {
							if (($imghd >= 0)&&($imghd <=14)) {
								$retorno = "13";
							} else {
								$retorno = "0";
							}
						} elseif (($swhd > 0)&&($swhd < 0.3080 )) {
							$retorno = "0";
						} else {
							$retorno = "13";
						}
					}
				}
				
			}
			unlink($file_a);
		break;



		default:
			$retorno = "13";
			$this->debug("SE HA PRODUCIDO UN ERROR 400");
		break;
		
		return $retorno;
	}

	function get_silence($nfile, $duracion_audio) {
		if (!is_dir("/srv/tmp")) {
			$crear = mkdir("/srv/tmp", 0764, true);
		}
		$file_a = "/srv/tmp/filesa_".rand(1,99999).".wav";
		$file_b = "/srv/tmp/filesb_".rand(1,99999).".wav";
		$file_c = "/srv/tmp/filesc_".rand(1,99999).".wav";
		
		# Obtener silencio Inicial
		$linea = "/usr/local/bin/sox $nfile $file_a silence 1 1 1% > /dev/null 2>&1";
		$this->debug($linea);
		$e = exec($linea);
		
		$newdur_in = $this->audio_duracion($file_a);
		$silencio_inicial = $duracion_audio - $newdur_in;
		unlink($file_a);
		
		# Obtener silencio Final
		$linea = "/usr/local/bin/sox $nfile $file_b reverse > /dev/null 2>&1";
		$this->debug($linea);
		$e = exec($linea);			
		$linea = "/usr/local/bin/sox $file_b $file_c silence 1 1 1% > /dev/null 2>&1";
		$this->debug($linea);
		$e = exec($linea);
		$newdur_fin = $this->audio_duracion($file_c);
		$silencio_final = $duracion_audio - $newdur_fin;
		unlink($file_b);
		unlink($file_c);
		$silencios = array();
		$silencios['si'] = $silencio_inicial;
		$silencios['sf'] = $silencio_final;
		return  $silencios;
	}
	function check_resultados($etiqueta, $intento) {
		$this->debug("VERIFICANDO EL RESULTADO DE LAS MEDICIONES");
		if (!$etiqueta) {
			$this->debug("VIENE SIN TAREA");
			return false;
		}

		# Obtener la configuracion de tarea
		$tarea = $this->memcache->get($etiqueta);
		if (!$tarea) {
			$this->debug("NO SE PUEDE RESCATAR TAREA DE MEMCACHE");
			return false;
		}
		
		# Determinar el resultado de las mediciones
		# Obtener los resultados
		$datos = $this->memcache->get("resultado.".$etiqueta);
		if (!$datos) {
			$this->debug("NO SE PUEDE RESCATAR RESULTADOS DE MEMCACHE");
			return false;
		}
		
		# Construir XML de Resultado
		$monitor_id = $tarea[0]['mon_id'];
		$fecha_ahora = date("Y-m-d H:i:s+u", $tarea[0]['starttime']);
		$fecha = date("Y-m-d H:i:s+u", $datos[0]['fecha']);
		$objetivo_id = $tarea[0]['obj_id'];
		$unique = $datos[0]['uniqueid'];
		$xml = <<<XML
<?xml version='1.0'?>
<atentus>
<resultado>
<ativr monitor_id='$monitor_id' fecha='$fecha_ahora' objetivo_id='$objetivo_id' intento='$intento' fecha_monitoreo='$fecha' asterisk_llamada_id='$unique'>

XML;
		
				
		foreach ($datos as $key => $value) {
			if ($key == 0) {
				if ($value['uniqueid'] == "") {
					$resultado_fase = 601;
					$stdout_acumulado .= "601|";
					$delay_acumulado .= "0|";
					$diff = 0;
					$this->debug("TIEMPO DE DISCADO: ".$diff);
				} else {
					$t1 = $tarea[0]['starttime'];
					$t2 = $value['fecha'];
					$diff = $t2 - $t1;
					$this->debug("TIEMPO DE DISCADO: $t1 -- $t2 ".$diff);
					$diff = $diff * 1000;
					$resultado_fase = 0;
					$stdout_acumulado .= "0|";
					$delay_acumulado .= "$diff|";
				}
				$xml_add .= <<<XML
<paso paso_orden='0'>
<delay>$diff</delay>
<status>$resultado_fase</status>
<analisis>
<silencio_inicial></silencio_inicial>
<silencio_final></silencio_final>
<sound_wave></sound_wave>
<spectrogram></spectrogram>
<path>
<png></png>
<wav></wav>
</path>
</analisis>
</paso>
XML;
			} else {
				# variables de tarea
				$tarea_duracion = $tarea[$key]['audio_duracion'];
				$tarea_visible = $tarea[$key]['visible'];
				$tarea_ti = $tarea[$key]['tolerancia_inicial'];
				$tarea_tf = $tarea[$key]['tolerancia_final'];
				$tarea_si = $tarea[$key]['audio_si'];
				$tarea_sf = $tarea[$key]['audio_sf'];
				$tipo_comparacion = $tarea[$key]['tipo_comparacion'];;
				$tarea_fin = $tarea[$key]['fin'];
				if ($tarea_fin == 1) {
					$char = "";
				} else {
					$char = "|";
				}

				
				# variables de resultado
				$si = ceil($value['silencio_inicial'] * 1000);
				$sf = ceil($value['silencio_final'] * 1000);
				$swhd = $value['soundwave_hd'];
				$imghd = $value['spec_hd'];
				$archivo = $value['archivo'];
				$img = $value['spec_file'];
				$fase_duracion = $value['duracion'];

				
				
				# La validacion depende del tipo de comparacion que se debe realizar
				switch($tipo_comparacion) {
					# No se Compara
					case '0':
						if($si > 0) {
							$resultado_fase = 0;
							$stdout_acumulado .= "0$char";
						} else {
							$resultado_fase = 602;
							$stdout_acumulado .= "602$char";							
						}
					break;
					
					# Comparacion Normal
					case '1':
						# Primera validacion, que exista el resultado y que la diferencia grabada no sea mayor a 2 segundos
						$diferencia_duracion = $tarea_duracion - $fase_duracion;
						
						if ((!$fase_duracion)||($diferencia_duracion > 2)){
							$resultado_fase = 602;
							$stdout_acumulado .= "602$char";
							
						} else {
							# Validar de inmediato cuanto SW sea negativo
							if ($swhd <= 0) {
								if (($imghd >= 0)&&($imghd <=14)) {
									$resultado_fase = 0;
									$stdout_acumulado .= "0$char";
								} else {
									$resultado_fase = 13;
									$stdout_acumulado .= "13$char";
									$this->debug("Error 605");
								}
							} elseif (($swhd > 0)&&($swhd < 0.3080 )) {
								$resultado_fase = 13;
								$stdout_acumulado .= "13$char";
								$this->debug("Error 604");
							} else {
								$resultado_fase = 0;
								$stdout_acumulado .= "0$char";
							}	
						}
					break;
					
					# Caso especial. Comparación condicional, incluye dos segmentos, uno con audio estático y otro con audio dinámico. El tiempo de respuesta es el silencio de segmento primero
					case '2':
						$duracion_minima_primero = $tarea[$key]['duracion_minima_primero'];
						$duracion_minima_segundo = $tarea[$key]['duracion_minima_segundo'];
						$dur_min = $duracion_minima_primero + $duracion_minima_primero;
						# Evaluar la duracion, con la suma las minimas
						$diferencia_duracion = $dur_min - $fase_duracion;
						if ($diferencia_duracion > 0 ) {
							$resultado_fase = 13;
							$stdout_acumulado .= "13$char";
						} else {
							# Ahora segmentar primer audio
							$arr_ce['duracion_segmento_primero'] = $tarea[$key]['duracion_segmento_primero'];
							$arr_ce['silencio_min_segundo_seg'] = $tarea[$key]['silencio_min_segundo_seg'];
							$arr_ce['silencio_max_segundo_seg'] = $tarea[$key]['silencio_max_segundo_seg'];
							$arr_ce['duracion_minima_primero'] = $duracion_minima_primero;
							$arr_ce['duracion_minima_segundo'] = $duracion_minima_segundo;
							$arr_ce['tolerancia_inicial'] = $tarea[$key]['tolerancia_inicial']/ 1000;
							$res_ce = caso_especial(2, $archivo, $arr_ce);
							$resultado_fase = $res_ce;
							$stdout_acumulado .= $res_ce."$char";
						}
						
					break;
					
					# Caso Especial. Comparación sólo del audio dinámico. El tiempo de respuesta es el silencio inicial
					case '3':
						$arr_ce['silencio_min_segundo_seg'] = $tarea[$key]['silencio_min_segundo_seg'];
						$arr_ce['silencio_max_segundo_seg'] = $tarea[$key]['silencio_max_segundo_seg'];
						$arr_ce['duracion_minima_segundo'] = $duracion_minima_segundo;
						$res_ce = caso_especial(3, $archivo, $arr_ce);
						$resultado_fase = $res_ce;
						$stdout_acumulado .= $res_ce."$char";
					break;
					
					# Caso Especial. Comparación condicional, incluye dos segmentos, uno con audio estático y otro con audio dinámico, y comparación inversa
					case '4':
						$arr_ce['duracion_segmento_primero'] = $tarea[$key]['duracion_segmento_primero'];
						$arr_ce['duracion_minima_primero'] = $duracion_minima_primero;
						$arr_ce['duracion_minima_segundo'] = $duracion_minima_segundo;
						$arr_ce['tolerancia_inicial'] = $tarea[$key]['tolerancia_inicial']/ 1000;
						$arr_ce['archivo_original'] = $tarea[$key]['path'];
						$arr_ce['imagen_original'] = $tarea[$key]['imagen_path'];
						$res_ce = caso_especial(2, $archivo, $arr_ce);
						$resultado_fase = $res_ce;
						$stdout_acumulado .= $res_ce."$char";
					break;
					
					default:
						# No agregamos nada, si no esta el paso de comparacion saldremos antes de ejecutar el robot
					break;
				}
				
				
				# Delays
				if ($resultado_fase != 0) {
					$delay_acumulado .= "-1000"."$char";
					$delay = "-1000";
				} else {
					$delay_acumulado .= "$si"."$char";
					$delay = $si;
				}

				
				$xml_add .= <<<XML
<paso paso_orden='$key'>
<delay>$delay</delay>
<status>$resultado_fase</status>
<analisis>
<silencio_inicial>$si</silencio_inicial>
<silencio_final>$sf</silencio_final>
<sound_wave>$swhd</sound_wave>
<spectrogram>$imghd</spectrogram>
<path>
<png>$img</png>
<wav>$archivo</wav>
</path>
</analisis>
</paso>
XML;
			}
		}
		
		$t = ksort($tarea);
		$a = count($tarea) +1;
		$b = count($datos) +1;
		$resta = $a - $b;
		# Agregar pasos malos
		if ($resta > 0) {
			for ($i = $b; $i < $a; $i++) {
				if ($i == ($a - 1)) {
					$char = "";
				} else {
					$char = "|";
				}
				$resultado_fase = 602;
				$stdout_acumulado .= "602$char";
				$delay_acumulado .= "0$char";
				$xml_add .= <<<XML
<paso paso_orden='$i'>
<delay></delay>
<status>$resultado_fase</status>
<analisis>
<silencio_inicial></silencio_inicial>
<silencio_final></silencio_final>
<sound_wave></sound_wave>
<spectrogram></spectrogram>
<path>
<png></png>
<wav></wav>
</path>
</analisis>
</paso>
XML;
			}
		}
		$xml_foot = <<<XML
</ativr>
</resultado>
</atentus>
XML;
		# Para validar el XML usaremos esta funcion, para nada mas
		$fullxml = $xml.$xml_add.$xml_foot;
		$parsed_xml = simplexml_load_string($fullxml);
		if ($parsed_xml == false) {
			$this->debug("Error XML");
		}

		//$this->debug(print_r($parsed_xml));
		# Ahora crearemos el XML
		/*
		$doc = new DOMDocument();
		$doc->loadXML($fullxml);
		$doc->saveXML();
		$doc->save("/tmp/test.xml");
		*/
		# Guardar Registro en BBDD
		$fullxml = str_replace("'", "''", $fullxml);
		$inobjid = $tarea[0]['obj_id'];
		$inmonid = $tarea[0]['mon_id'];
		
		$infecha = date("Y-m-d H:i:s+u", $datos[0]['fecha']);
		$sql = "INSERT INTO resultado.resultado(objetivo_id, monitor_id, fecha, estado, tiempo, xml_resultado) VALUES ('$inobjid','$inmonid','$infecha','$stdout_acumulado','$delay_acumulado','$fullxml')";
		$this->debug($sql);
		$params = null;
		$check_query = $this->statement_sp($sql, $params);
		$this->debug($check_query);
		return $stdout_acumulado;
	}
	
	function salida_prematura($data, $etiqueta = null) {
		$this->debug("GENERADA UNA SALIDA PREMATURA DEL MONITOREO");		
		# Obtener la configuracion de tarea
		if ($etiqueta) {
			$tarea = $this->memcache->get($etiqueta);			
		} else {
			$tarea = 0;
		}

		
		# Construir XML de Resultado
		$monitor_id = $data[0]['mon_id'];
		$fecha_ahora = date("Y-m-d H:i:s+u", $data[0]['starttime']);
		$fecha = date("Y-m-d H:i:s+u", $data[0]['fecha']);
		$objetivo_id = $data[0]['obj_id'];
		$unique = $data[0]['uniqueid'];
		$intento = $data[0]['intento'];
		$xml = <<<XML
<?xml version='1.0'?>
<atentus>
<resultado>
<ativr monitor_id='$monitor_id' fecha='$fecha_ahora' objetivo_id='$objetivo_id' intento='$intento' fecha_monitoreo='$fecha' asterisk_llamada_id='$unique'>

XML;
		
				
		foreach ($data as $key => $value) {
			if ($key == 0) {
				$t1 = $value['starttime'];
				$t2 = $value['fecha'];
				$diff = $t2 - $t1;
				$this->debug("TIEMPO DE DISCADO: $t1 -- $t2 ".$diff);
				$diff = $diff * 1000;
				$resultado_fase = $value['resultado_fase'];
				$stdout_acumulado .= "$resultado_fase|";
				$delay_acumulado .= "$diff|";
			$xml_add .= <<<XML
<paso paso_orden='0'>
<delay>$diff</delay>
<status>$resultado_fase</status>
<analisis>
<silencio_inicial></silencio_inicial>
<silencio_final></silencio_final>
<sound_wave></sound_wave>
<spectrogram></spectrogram>
<path>
<png></png>
<wav></wav>
</path>
</analisis>
</paso>
XML;
			} else {
				# en una salida prematura esto no se ejecutaria 
			}
		} // fin foreach
		
		$t = ksort($tarea);
		$a = count($tarea) +1;
		$b = count($data) +1;
		$resta = $a - $b;
		# Agregar pasos malos
		if ($resta > 0) {
			for ($i = $b; $i < $a; $i++) {
				if ($i == ($a - 1)) {
					$char = "";
				} else {
					$char = "|";
				}
				$resultado_fase = 602;
				$stdout_acumulado .= "602$char";
				$delay_acumulado .= "0$char";
				$xml_add .= <<<XML
<paso paso_orden='$i'>
<delay></delay>
<status>$resultado_fase</status>
<analisis>
<silencio_inicial></silencio_inicial>
<silencio_final></silencio_final>
<sound_wave></sound_wave>
<spectrogram></spectrogram>
<path>
<png></png>
<wav></wav>
</path>
</analisis>
</paso>
XML;
			}
		}
		$xml_foot = <<<XML
</ativr>
</resultado>
</atentus>
XML;
		# Para validar el XML usaremos esta funcion, para nada mas
		$fullxml = $xml.$xml_add.$xml_foot;
		$parsed_xml = simplexml_load_string($fullxml);
		if ($parsed_xml == false) {
			$this->debug("Error XML");
		}
		# Guardar Registro en BBDD
		$fullxml = str_replace("'", "''", $fullxml);
		$infecha = date("Y-m-d H:i:s+u", $data[0]['fecha']);
		/*
		$sql = "INSERT INTO resultado.resultado(objetivo_id, monitor_id, fecha, estado, tiempo, xml_resultado) VALUES ('$objetivo_id','$monitor_id','$infecha','$stdout_acumulado','$delay_acumulado','$fullxml')";
		$this->debug($sql);
		$params = null;
		$check_query = $this->statement_sp($sql, $params);
		
		$this->debug($check_query);
		*/
		$this->debug($fullxml);
		return /*$stdout_acumulado*/;
	}
}
?>