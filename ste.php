<?php
namespace ste;

interface cache
{
	public function is_cached($name);
	public function store($name, $data);
	public function execute($name);
}

class file_cache implements cache
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
		return file_exists($file);
	}

	public function store($name, $data) {
		$file = $this->__locate($name);
		@file_put_contents($file, $data);
		$this->memcache[$name] = $data;
	}

	public function execute($name) {
		$file = $this->__locate($name);
		if (is_readable($file)) {
			require($file);
		} else if (isset($this->memcache[$name])) {
			eval('?>' . $this->memcache[$name]);
		} else {
			throw(new Exception("Can't load template cache '{$name}'"));
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

	public function __construct($path, $cache = './phpste_cache') {
		if (is_string($cache)) $cache = new file_cache($cache);
		$this->path = realpath($path);
		$this->cache = $cache;
		
		if (!($cache instanceof cache)) {
			throw(new Exception("Cache must implement the interface 'ste\\cache'."));
		}

		// Load the base plugins.
		$this->plugin('plugin_base');
	}

	// Loads a plugin.
	public function plugin($class) {
		$this->plugins[] = $class;
	}
	
	public function get_contents($name) {
		$rname = realpath("{$this->path}/{$name}.php");
		if (substr_compare($this->path, $rname, 0, strlen($this->path), false) != 0) {
			throw(new Exception("Template '{$rname}' out of the safe path '{$this->path}'."));
		}
		return file_get_contents($rname);
	}
	
	public function parse_init() {
		foreach ($this->plugins as $plugin_class) {
			$rclass = new ReflectionClass($plugin_class);
			foreach ($rclass->getMethods() as $method) {
				$name = $method->name;
				if (substr($name, 0, 4) == 'tag_') $this->tags[substr($name, 4)] = $plugin_class;
			}
		}
	}
	
	public function parse($data) {
		$this->parse_init();
		return node_parser::get(
			$this,
			preg_split('@(\{[^}]*\})@Umsi', $data, -1, PREG_SPLIT_DELIM_CAPTURE)
		);
	}
	
	public function parse_file($name) {
		return $this->parse($this->get_contents($name));
	}
	
	public function process_block(node $node) {
		$node_name = $node->name;

		if (!isset($this->tags[$node_name])) throw(new Exception("Unknown tag '{$node_name}'"));

		$class  = $this->tags[$node_name];
		$method = "tag_{$node_name}";

		return $class::$method($node);
	}
	
	public function show($name) {
		if (!$this->cache->is_cached($name)) {
			$this->cache->store($name, (string)$this->parse_file($name));
		}
		$this->cache->execute($name);
	}
	
	public function get($name) {
		ob_start();
		{
			$this->show($name);
		}
		return ob_get_clean();
	}
}

class node
{
	public $ref;
	public $is_root = false;
	public $node_parser, $ste;
	public $data, $name = 'unknown', $line = -1, $params = array();
	public $a = '', $b = array(), $c = '';
	
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
		foreach ($this->b as &$v) { if ($v instanceof node) return false; }
		return implode('', $this->b);
	}
	
	public function emptytag() {
		if (isset($this->ref)) return $this->ref->emptytag();
		//$this->setref($this->createnode());
		list($this->a, $this->b, $this->b) = array('', array(), '');
	}
	
	public function createnode() {
		return new static($this->node_parser);
	}
}

class node_parser
{
	public $tokens, $n, $count;
	public $tree;
	public $ste;
	public $line;
	
	public function __construct(ste $ste, &$tokens, $name = 'unknown') {
		$this->ste = $ste;
		$this->tokens = $tokens;
		$this->name = $name;
		$this->line = 1;
		$this->n = 0;
		$this->count = count($tokens);
		$this->tree = $this->createnode();
		$this->tree->is_root = true;
	}
	
	public function createnode($current_line = -1) {
		$node = new node($this);
		$node->line = $current_line;
		return $node;
	}
	
	public function parse_params(node $node, $string) {
		$node->data = $string;
		@list($node->name, $params) = explode(' ', $node->data, 2);
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
			$parent_node = $this->tree;
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
							throw(new Exception("Mismatch opening/closing tag. Opening:({$parent_node->name}:{$parent_node->line}) Closing({$data}:{$current_line})"));
						}
						$this->ste->process_block($parent_node);

						return $parent_node;
					break;
					// Opening+closing node.
					case '!':
						$node = $this->createnode($current_line);
						$this->parse_params($node, substr($c, 2, -1));
						$this->ste->process_block($node);
						$parent_node->add($node);
					break;
					// Variables.
					case '$':
						$parent_node->add('<?php echo ' . substr($c, 1, -1) . '; ?>');
					break;
					// Opening node.
					default:
						$node = $this->createnode($current_line);
						$this->parse_params($node, substr($c, 1, -1));
						$this->process($node);
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
			throw(new Exception("Not closed tag({$parent_node->name}:{$parent_node->line})"));
		}
		
		return $parent_node;
	}

	// Static method to obtain a parsed tree.
	static public function get(ste $ste, $tokens) {
		$tp = new static($ste, $tokens);
		$tp->process();
		return $tp->tree;
	}
}

class plugin_base
{
	static public function tag_block(node $node) {
		$block_id = $node->params['id'];
		$block = &$node->ste->blocks[$block_id];
		if (!isset($block)) $block = $node;
		$block->setref($node);
	}

	static public function tag_blockdef(node $node) {
		static::tag_block(clone $node);
		$node->emptytag();
	}
	
	static public function tag_t(node $node) {
		$lit = $node->literal();
		if ($lit === false) {
			$node->a = '<?php ob_start(); ?>';
			$node->c = '<?php echo _(ob_get_clean()); ?>';
		} else {
			$node->b = array('<?php echo _(' . var_export($lit, true) . '); ?>');
		}
	}

	static public function tag_for(node $node) {
		$node->a = '<?php for ($n = 0; $n < 10; $n++) { ?>';
		$node->c = '<?php } ?>';
	}
	
	static public function tag_extends(node $node) {
		$node->node_parser->tree = $node->ste->parse_file($node->params['name']);
	}
	
	static public function tag_putblock(node $node) {
		$node->b = array($node->ste->blocks[$node->params['id']]);
	}
}
?>