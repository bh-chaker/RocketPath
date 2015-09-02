<?php

function rocket_path_parse_location_header(){
	foreach (headers_list() as $header) {
		$url = null;
		if (strpos($header, 'location') === 0){
			$url = trim(substr($header, strpos($header, ':')+1));
			header_remove('location');
		}
		if (strpos($header, 'Location') === 0){
			$url = trim(substr($header, strpos($header, ':')+1));
			header_remove('Location');
		}
		if ($url !== null){
			header('Location: '. RocketPath::parse_url($url));
		}
	}
}

function rocket_path_parse_output_buffer($buffer, $flags){
	// Prevent parsing $buffer when it's not HTML (at least try to guess)
        if ( strlen($b = trim($buffer)) < 20 || !preg_match('/<(a|form|script)\b/', $b) ) {
            return $buffer;
        }
	try{
		$template = new DOMTemplate($buffer);
		RocketPath::parse_template($template);
		return $template->html();
	}
	catch(Exception $e){
		return $buffer;
	}
}

class RocketPath implements RocketSled\Runnable{

	private static $rules = array();
	private static $default_rules = array();
	private static $base_dir = "/";
	private static $enabled = false;
	private static $rewrites_enabled = false;
	private static $userland = '../';

	public function run(){
		$rewrites = Args::get('rewrites',Args::argv);
		if ($rewrites && $rewrites == "nginx"){
			self::set_rules($rules);
			echo "== Start Nginx Rewrites".PHP_EOL;
			$nginx_filename = Args::get('filename',Args::argv);
			$nginx_folder = Args::get('folder',Args::argv);
			if (!$nginx_filename || !$nginx_folder){
				echo "Missing arguments";
				exit(0);
			}
			self::save_nginx_rewrites($nginx_filename);
			if (!self::check_nginx_config($nginx_filename, $nginx_folder)){
				echo "Nginx config is outdated, please run the following commands:".PHP_EOL;
				echo "cp ".$nginx_filename." ".$nginx_folder.$nginx_filename.PHP_EOL;
				echo "/etc/init.d/nginx restart".PHP_EOL;
			}
			echo "== End Nginx Rewrites".PHP_EOL;
			exit(0);
		}

		$generate_default_rules = Args::get('generate-default-rules',Args::argv);
		if ($generate_default_rules){
			global $dirs;
			global $current_hostname;
			self::set_userland($dirs[$current_hostname]['userland']);
			echo "== Updating Default Rules...".PHP_EOL;
			RocketPath::generate_default_rules();
			RocketPath::write_default_rules_to_file('rules.default.php');
			echo '== Default Rules Updated !'.PHP_EOL;
			exit(0);
		}
	}

	public static function set_default_rules($default_rules){
		self::$default_rules = $default_rules;
	}

	public static function get_default_rules(){
		return self::$default_rules;
	}

	public static function set_rules($rules){
		self::$rules = $rules;
	}

	public static function get_rules(){
		return self::$rules;
	}

	public static function set_base_dir($base_dir){
		if (!RocketSled::endsWith($base_dir, "/")){
			$base_dir = $base_dir . "/";
		}
		self::$base_dir = $base_dir;
	}

	public static function get_base_dir(){
		return self::$base_dir;
	}

	public static function set_enabled($enabled){
		self::$enabled = $enabled;
	}

	public static function get_enabled(){
		return self::$enabled;
	}

	public static function set_rewrites_enabled($rewrites_enabled){
		self::$rewrites_enabled = $rewrites_enabled;
	}

	public static function get_rewrites_enabled(){
		return self::$rewrites_enabled;
	}

	public static function set_userland($userland){
		self::$userland = realpath($userland);
	}

	public static function get_userland(){
		return self::$userland;
	}

	public static function get_php_class($php_code) {
		$class = "";
	  	$namespace = "";
	  	$tokens = token_get_all($php_code);
	  	$count = count($tokens);
	  	for ($i = 2; $i < $count; $i++) {
			if (   $tokens[$i - 2][0] == T_CLASS
				&& $tokens[$i - 1][0] == T_WHITESPACE
				&& $tokens[$i][0] == T_STRING
				&& $class == "") {
				$class = $tokens[$i][1];
			}

			if (   $tokens[$i - 2][0] == T_NAMESPACE
				&& $tokens[$i - 1][0] == T_WHITESPACE
				&& $tokens[$i][0] == T_STRING
				&& $namespace == "") {
				while ($tokens[$i] != ";"){
					$namespace .= $tokens[$i][1];
					$i++;
				}
			}

			if ($class != "" && $namespace != ""){
				break;
			}
	  	}

	  	return $namespace . "\\" .$class;
	}

	public static function file_get_php_class($filepath) {
		$php_code = file_get_contents($filepath);
		$class = self::get_php_class($php_code);
		return $class;
	}

	public static function filename2path($filename){
		$filename = substr($filename, 0, strlen($filename)-strlen(".class.php"));
		return str_replace("_", "-", $filename);
	}

	public static function prepare_rule($regex, $params){
		$rule = '\?r='.preg_quote($params[0]);
		$count = 0;
		$regex = preg_replace_callback ( '/\(.*?\)/' ,
								function ($matches) use ($params, &$rule, &$count){
									$count++;
									$rule .= '&'.$params[$count].'='.$matches[0];
									return '$'.$count;
								} ,
								$regex);

		return array($rule, $regex);
	}

	public static function extract_class ($var){
		$var = realpath($var);
		if (strpos($var, self::$userland)!==0){
			return false;
		}

		if (!RocketSled::endsWith($var, ".class.php")){
			return false;
		}

		$classname = self::file_get_php_class($var);
		try{
			$refl = new ReflectionClass($classname);
			if(!$refl->implementsInterface('RocketSled\\Runnable')){
				return false;
			}
		}
		catch (Exception $e){
			return false;
		}

		$filename = substr($var, strrpos($var, "/")+1);
		$path = self::filename2path($filename);
		if (strlen($path) > 0){
			self::$default_rules[self::filename2path($filename)] = $classname;
		}

		return  true;
	}

	public static function generate_default_rules(){
		self::$default_rules = array();
		RocketSled::filteredPackages("RocketPath::extract_class");
		return self::$default_rules;
	}

	public static function write_default_rules_to_file($filename){
		$default_rules = self::$default_rules;
		$file = fopen($filename, 'w');
		fwrite($file, '<?php' . PHP_EOL);
		fwrite($file, '$rules = array('. PHP_EOL);

		foreach ($default_rules as $path => $classname){
			fwrite($file, "'$path' => array('$classname',)," . PHP_EOL);
		}

		fwrite($file, ');' . PHP_EOL);
		fclose($file);
	}

	public static function init_runnable_class(){
		if (!self::$enabled){
			return true;
		}

		if (isset($_GET['r']) || !isset($_GET['path'])){
			return true;
		}

		$rules = self::$rules;

		if (strlen($_GET['path']) > 0 && $_GET['path'][strlen($_GET['path'])-1] == "/"){
			$_GET['path'] = substr($_GET['path'], 0, strlen($_GET['path'])-1);
		}

		foreach ($rules as $key => $val){
			$i = 1;
			preg_replace_callback ( '/^'.str_replace('/', '\\/', $key).'$/' ,
							function ($matches) use ($val, $i){
								$_GET['r'] = $val[0];
								if (count($matches) == count($val)){
									for ($i=1; $i<count($matches); $i++){
										$_GET[$val[$i]] = $matches[$i];
									}
								}
							 	return '';
							} ,
							$_GET['path']);
			if (isset($_GET['r'])){
				return true;
			}
		}

		exit(0);
	}

	public static function runnable($rules_file, $base_dir, $rewrites_enabled){
		if (file_exists($rules_file)){
			require_once($rules_file);
			self::set_rules($rules);
		}

		//header_register_callback('rocket_path_parse_location_header');
		ob_start('rocket_path_parse_output_buffer');

		self::set_enabled(true);
		self::set_rewrites_enabled($rewrites_enabled);
		self::set_base_dir($base_dir);
		self::init_runnable_class();

		return RocketSled::defaultRunnable();
	}

	public static function parse_url($url){
		if (!self::$enabled){
			return $url;
		}

		$rules = self::$rules;

		if (strpos($url, '?r=') === 0){
			foreach ($rules as $regex => $params){
				$result = RocketPath::prepare_rule($regex, $params);
				$new_url = preg_replace('/^'.$result[0].'$/', self::$base_dir.$result[1], $url);
				if ($new_url != $url){
					return $new_url;
				}
			}
			return self::$base_dir . $url;
		}
		else if (strpos($url, "#")!==0 && !preg_match('"^.*\.html$"', $url) && strpos($url, 'javascript')!==0 && strpos($url, '/')!==0 &&strpos($url, 'http') !== 0 && strpos($url, 'mailto:') !== 0 && strpos($url, 'tel:') !== 0){
			return self::$base_dir . $url;
		}

		return $url;
	}

	public static function parse_template($template){
		if (!self::$enabled){
			return;
		}
		foreach ($template->query("a") as $a){
			$href = $a->getAttribute('href');
			$a->setAttribute('href', self::parse_url($href));
		}

		foreach ($template->query("form") as $form){
			$action = $form->getAttribute('action');
			if ($action && $action!=""){
				$form->setAttribute('action', self::parse_url($action));
			}
		}

		foreach ($template->query("script") as $script){
			$src = $script->getAttribute('src');
			if ($src && $src!=""){
				$script->setAttribute('src', self::parse_url($src));
			}
		}

	}

	public static function get_nginx_rewrites(){
		$rules = self::$rules;
		$rewrites = array();
		$template = "rewrite ^/%regex%$ /index.php?r=%classname%%other_params% last;";
		foreach ($rules as $regex => $params){
			$line = $template;
			$line = str_replace("%regex%", $regex, $line);
			$line = str_replace("%classname%", $params[0], $line);
			$other_params = "";
			for ($i=1; $i<count($params); $i++){
				$other_params .= "&{$params[$i]}=\${$i}";
			}
			$line = str_replace("%other_params%", $other_params, $line);
			$rewrites[] = $line;
		}
		return $rewrites;
	}

	public static function save_nginx_rewrites($nginx_filename){
		$rewrites = self::get_nginx_rewrites();
		$file = fopen($nginx_filename, 'w');
		fwrite($file, 'location / {'.PHP_EOL);
        fwrite($file, 'index index.html index.htm index.php;'.PHP_EOL);
        fwrite($file, 'fastcgi_read_timeout 600;'.PHP_EOL);
        fwrite($file, 'if (!-e $request_filename) {'.PHP_EOL);

		foreach ($rewrites as $rewrite){
			fwrite($file, $rewrite . PHP_EOL);
		}

		fwrite($file, 'rewrite ^/(.*)$ /index.php?path=$1 last;'.PHP_EOL);
        fwrite($file, 'break;'.PHP_EOL);
        fwrite($file, '}'.PHP_EOL);
    	fwrite($file, '}'.PHP_EOL);
		fclose($file);
	}

	public static function check_nginx_config($nginx_filename, $nginx_folder){
		if (!file_exists($nginx_folder.$nginx_filename)){
			return false;
		}

		return sha1_file($nginx_filename) == sha1_file($nginx_folder.$nginx_filename);
	}
}
?>
