<?php
namespace ste;

interface cache
{
	public function is_cached($name);
	public function store($name, $data, $files);
	public function execute($name, &$params);
}

class cache_null implements cache
{
	public $memcache = array();

	public function is_cached($name) { return false; }
	public function store($name, $data, $files) { $this->memcache[$name] = $data; }
	public function execute($name, &$params) {
		if (!isset($this->memcache[$name])) throw(new \Exception("Can't load template cache '{$name}'"));
		extract($params);
		eval('?>' . $this->memcache[$name]);
	}
}

class cache_file extends cache_null implements cache
{
	public $path;
	public $memcache = array();
	
	public function __construct($path) {
		$this->path = $path;
		if (!is_dir($this->path)) @mkdir($this->path, 0777);
	}

	protected function __locate($name) {
		return $this->path . '/' . urlencode($name) . '.cache';
	}

	public function is_cached($name) {
		$file = $this->__locate($name);
		if (!file_exists($file_info = "{$file}.info")) return false;
		foreach (@unserialize(file_get_contents($file_info)) as $file => $time) {
			if (@filemtime($file) != $time) return false;
		}
		return file_exists($file);
	}

	public function store($name, $data, $files) {
		$file = $this->__locate($name);
		{
			$rfiles = array(); $files[] = __FILE__;
			foreach ($files as $cfile) $rfiles[$cfile] = filemtime($cfile);
		}
		@file_put_contents("{$file}.info", serialize($rfiles));
		@file_put_contents($file, $data);
		parent::store($name, $data, $files);
	}

	public function execute($name, &$params) {
		$file = $this->__locate($name);
		if (is_readable($file)) {
			extract($params);
			require($file);
		} else {
			parent::execute($name, $params);
		}
	}
}

class ste
{
	public $path;
	public $cache;
	public $plugins = array();
	public $tags    = array();
	public $blocks  = array();

	protected $plugins_cached = array();
	protected $parsed_files = array();

	public function __construct($path, $cache = null) {
		if ($cache === null) $cache = new cache_null();
		else if (is_string($cache)) $cache = new cache_file($cache);
		$this->path = realpath($path);
		$this->cache = $cache;
		
		if (!($cache instanceof cache)) {
			throw(new \Exception("Cache must implement the interface 'ste\\cache'."));
		}

		// Load the base plugins.
		$this->plugin('\\ste\\plugin_base');
	}

	// Loads a plugin.
	public function plugin($class) {
		$this->plugins[] = $class;
	}
	
	public function get_path($name) {
		$rname = realpath("{$this->path}/{$name}.php");
		if (substr_compare($this->path, $rname, 0, strlen($this->path), false) != 0) {
			throw(new \Exception("Template '{$rname}' out of the safe path '{$this->path}'."));
		}
		return $rname;
	}
	
	public function get_contents($name) {
		return file_get_contents($this->get_path($name));
	}
	
	public function parse_init() {
		if ($this->plugins == $this->plugins_cached) return;
		$this->plugins_cached = $this->plugins;
		$this->tags = array();
		foreach ($this->plugins as $plugin_class) {
			$rclass = new \ReflectionClass($plugin_class);
			foreach ($rclass->getMethods() as $method) {
				$name = $method->name;
				if (preg_match('/^TAG_(PRE|POST|FINAL)_(\\w+)$/', $name, $matches)) {
					list(, $type, $tname) = $matches;
					$ct = &$this->tags[$tname];
					if (!isset($ct)) $ct = array();
					$ct[$type] = array($plugin_class, $name);
				}
			}
		}
	}
	
	public function parse($data, $name = 'unknown') {
		$final = array();
		foreach (explode('{literal}', $data) as $k => $chunk) {
			if ($k % 2 == 0) {
				$final = array_merge($final, preg_split('@(\{[^}]*\})@Umsi', $chunk, -1, PREG_SPLIT_DELIM_CAPTURE));
			} else {
				$final[] = $chunk;
			}
		}
		$this->parse_init();
		return node_parser::get($this, $final, $name);
	}
	
	public function parse_file($name) {
		$c = &$this->parsed_files[$this->get_path($name)];
		if (!isset($c)) $c = $this->parse($this->get_contents($name), $name);
		return $c;
	}

	public function process_block(node $node, $step, $line = -1) {
		$node_name = $node->name;

		if ($line == -1) $line = $node->line;
		if (!isset($this->tags[$node_name])) throw(new \Exception("Unknown tag '{$node_name}' at line {$line}"));
		
		$call = &$this->tags[$node_name][$step];
		if (!isset($call)) return null;

		return call_user_func($call, $node);
		//return $call($node);
	}
	
	protected function cleanup($text) {
		return str_replace('?><?php', '', $text);
	}
	
	public function show($name, $params = array()) {
		if (!$this->cache->is_cached($name)) {
			$result = $this->parse_file($name);
			$this->cache->store($name, $this->cleanup(node::sgenerate($result)), array_keys($this->parsed_files));
		}
		$this->cache->execute($name, $params);
	}
	
	public function get($name, $params = array()) {
		ob_start();
		{
			$this->show($name, $params);
		}
		return ob_get_clean();
	}
}

class NodeException extends \Exception
{
	public function __construct(node $node, $str) {
		if (!strlen($str)) $str = 'unknown error';
		parent::__construct("tag({$node->name}) file({$node->file}:{$node->line}): {$str}");
	}
}

class node_parser
{
	public $tokens, $n, $count;
	public $node_root;
	public $ste;
	public $line;
	public $name;
	
	public function __construct(ste $ste, &$tokens, $name = 'unknown') {
		$this->ste = $ste;
		$this->tokens = $tokens;
		$this->name = $name;
		$this->line = 1;
		$this->n = 0;
		$this->count = count($tokens);
		$this->node_root = $this->createnode();
		$this->node_root->is_root = true;
	}
	
	public function createnode($current_line = -1) {
		$node = new node($this);
		$node->line = $current_line;
		$node->file = $this->name;
		return $node;
	}
	
	public function parse_params(node $node, $string) {
		$node->data = $string;

		$current = explode(' ', $node->data, 2);
		if (isset($current[0])) $node->name = $current[0];
		$params = isset($current[1]) ? $current[1] : null;

		preg_match_all('/(\\w+)=(\'[^\']+\'|"[^"]+"|\\S+)/', $params, $matches, PREG_SET_ORDER);
		$node->params = array();
		foreach ($matches as $match) {
			$v = $match[2];
			if ($v[0] == '"' || $v[0] == "'") $v = stripslashes(substr($v, 1, -1));
			$node->params[$match[1]] = $v;
		}
	}
	
	public function process($parent_node = null) {
		if ($parent_node === null) {
			$parent_node = $this->node_root;
		}

		while ($this->n < $this->count) {
			if (!strlen($c = $this->tokens[$this->n++])) continue;
			$current_line = $this->line;
			$this->line += substr_count($c, "\n");

			// It's a node.
			if ($c[0] == '{') {
				// Kind of node.
				switch ($c[1]) {
					// Closing node.
					case '/':
						$data = substr($c, 2, -1);
						if ($data != $parent_node->name) {
							throw(new NodeException($parent_node, "Mismatch opening/closing tag. Closing({$data}:{$current_line})"));
						}
						$this->ste->process_block($parent_node, 'POST', $current_line);

						return $parent_node;
					break;
					// Variables.
					case '$':
						$node = $this->createnode($current_line);
						$node->a = '<?php echo ' . substr($c, 1, -1) . '; ?>';
						$parent_node->add($node);
					break;
					// Opening node.
					default:
						$node = $this->createnode($current_line);
						
						$node->parent_node = $parent_node;
						$this->parse_params($node, substr($c, 1, -1));
						$this->ste->process_block($node, 'PRE');
						if ($node->mustclose) {
							$this->process($node);
						}
						$parent_node->add($node);
					break;
				}
			}
			// Text.
			else {
				$parent_node->add($c);
			}
		}
		
		if (!$parent_node->is_root) {
			throw(new NodeException($parent_node, "Not closed"));
		}
		
		return $parent_node;
	}

	// Static method to obtain a parsed node_root.
	static public function get(ste $ste, $tokens, $name = 'unknown') {
		$tp = new static($ste, $tokens, $name);
		$tp->process();
		return $tp->node_root;
	}
}

class node
{
	public $is_root = false;
	public $parent_node = null;
	public $node_parser, $ste;
	public $data, $name = 'unknown', $file = 'unknown', $line = -1, $params = array();
	public $a = '', $b = array(), $c = '';
	public $generate_callback = null;
	public $mustclose = false;

	protected $ref;
	
	static public function sgenerate(&$that) {
		if (!($that instanceof node)) return $that;
		if (isset($that->ref)) return static::sgenerate($that->ref);

		if (!$that->is_root) $that->ste->process_block($that, 'FINAL');
		$s = '';
		$s .= static::sgenerate($that->a);
		foreach ($that->b as $e) $s .= static::sgenerate($e);
		$s .= static::sgenerate($that->c);
		return $s;
	}
	
	public function setref($that) {
		if ($this !== $that) $this->ref = $that;
	}

	public function add($v) {
		if (isset($this->ref)) return $this->ref->add($v);
		$this->b[] = $v;
	}

	public function __construct(node_parser $node_parser) {
		$this->node_parser = $node_parser;
		$this->ste = $node_parser->ste;
	}
	
	public function __tostring() {
		if (isset($this->ref)) return $this->ref->__tostring();
		return $this->a . implode('', $this->b) . $this->c;
	}

	public function literal() {
		if (isset($this->ref)) return $this->ref->literal();
		foreach ($this->b as &$v) if ($v instanceof node && ($v->literal() === false)) return false;
		$ret = implode('', $this->b);
		if (strpos($ret, '<?') !== false) return false;
		return $ret;
	}
	
	public function emptytag() {
		if (isset($this->ref)) return $this->ref->emptytag();
		//$this->setref($this->createnode());
		list($this->a, $this->b, $this->b) = array('', array(), '');
	}
	
	public function createnode() {
		return new static($this->node_parser);
	}
	
	public function checkParams($allow_more = true, $params_check = array()) {
		if (!$allow_more && count($dif_list = array_diff_key($this->params, $params_check)) > 0) {
			throw(new NodeException($this, "One or more unknown parameters: '" . implode(',', array_keys($dif_list)) . "'"));
		}
		
		foreach ($params_check as $name => $info) {
			while (count($info) < 3) $info[] = null;
			@list($type, $default_value, $error) = $info;
			$value = &$this->params[$name];
			if (isset($value)) {
				switch ($type) {
					case 'var':
						if (!preg_match('/^\\$[a-z_][a-z0-9_]*$/i', $value)) throw(new NodeException($this, "Parameter '{$name}' should be a variable"));
					break;
					case 'expr':
						// TODO.
					break;
					case 'int':
						if ($value != (int)$value) throw(new NodeException($this, "Parameter '{$name}' should be an integer number"));
					break;
					case 'number':
						if (!is_numeric($value)) throw(new NodeException($this, "Parameter '{$name}' should be a number"));
					break;
				}
			}
			// It's not mandatory. We have a default value.
			else if ($default_value !== null) {
				$value = $default_value;
			}
			// Not defined and without default value.
			else {
				throw(new NodeException($this, (($error !== null) ? $error : "Expected parameter '{$name}'")));
			}
		}
	}
}

class plugin_base
{
	static public function TAG_PRE_block(node $node) {
		$node->mustclose = true;
		$node->checkParams(false, array(
			'id' => array('id', null),
		));

		$block = &$node->ste->blocks[$block_id = $node->params['id']];

		if (!isset($block)) {
			$block = $node;
		} else {
			$block->setref($node);
		}
	}

	static public function TAG_PRE_blockdef(node $node) {
		$node->mustclose = true;
		static::TAG_block(clone $node);
		$node->emptytag();
	}
	
	static public function TAG_PRE_t(node $node) {
		$node->mustclose = true;
		$node->checkParams(false, array(
		));
	}

	static public function TAG_FINAL_t(node $node) {
		if (($lit = $node->literal()) !== false) {
			$node->b = array('<?php echo _(', var_export($lit, true), '); ?>');
		}
		// Not a literal, we should use buffering.
		else {
			$node->a = '<?php ob_start(); ?>';
			$node->c = '<?php echo _(ob_get_clean()); ?>';
		}
	}

	static public function TAG_PRE_for(node $node) {
		$node->mustclose = true;
		$node->checkParams(false, array(
			'var'  => array('var', null),
			'to'   => array('int', null),
			'from' => array('int', 0),
			'step' => array('int', 1),
		));

		$p = &$node->params;
		$node->a = "<?php for ({$p['var']} = {$p['from']}; {$p['var']} <= {$p['to']}; {$p['var']}++) { ?>";
		$node->c = '<?php } ?>';
	}

	static public function TAG_PRE_if(node $node) {
		$node->mustclose = true;
		$node->checkParams(false, array(
			'cond'  => array('expr', null),
		));

		$p = &$node->params;
		$node->a = "<?php if ({$p['cond']}) { ?>";
		$node->c = '<?php } ?>';
		$node->haselse = false;
	}

	static public function TAG_PRE_else(node $node) {
		$node->mustclose = false;
		$node->checkParams(false, array(
		));
		if ($node->parent_node->name != 'if') throw(new NodeException($node, "else must be in a if block"));

		$p = &$node->params;
		$node->a = "<?php } else { ?>";
		$node->parent_node->haselse = true;
	}

	static public function TAG_PRE_elseif(node $node) {
		$node->mustclose = false;
		$node->checkParams(false, array(
			'cond'  => array('expr', null),
		));
		if ($node->parent_node->name != 'if') throw(new NodeException($node, "else must be in a if block"));
		if ($node->parent_node->haselse) throw(new NodeException($node, "elseif can't be after the else"));

		$p = &$node->params;
		$node->a = "<?php } else if ({$p['cond']}) { ?>";
	}

	static public function TAG_PRE_foreach(node $node) {
		$node->mustclose = true;
		$node->checkParams(false, array(
			'list' => array('var', null),
			'var'  => array('var', null),
		));

		$p = &$node->params;
		$node->a = "<?php foreach ({$p['list']} as {$p['var']}) { ?>";
		$node->c = '<?php } ?>';
	}
	
	static public function TAG_PRE_extends(node $node) {
		$node->mustclose = false;
		$node->checkParams(false, array(
			'name' => array('string', null, 'Required name of the template to extend'),
		));
		$node->node_parser->node_root = $node->ste->parse_file($node->params['name']);
	}

	static public function TAG_PRE_include(node $node) {
		$node->mustclose = false;
		$node->checkParams(false, array(
			'name' => array('string', null, 'Required name of the template to extend'),
		));
		$node->setref($node->ste->parse_file($node->params['name']));
	}
	
	static public function TAG_PRE_putblock(node $node) {
		$node->mustclose = false;
		$node->checkParams(false, array(
			'id' => array('string', null),
		));

		$node->b = array($node->ste->blocks[$node->params['id']]);
	}

	static public function TAG_PRE_addblock(node $node) {
		$node->mustclose = true;
		$node->checkParams(false, array(
			'id' => array('id', null),
		));

		$block_id = $node->params['id'];
		$block = &$node->ste->blocks[$block_id];
		if (!isset($block)) {
			$block = $node;
		} else {
			$block->add("\n");
			$block->add($node);
		}
	}
}
?>