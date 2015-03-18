

# STE subsystems #

## Main class interface (ste) ##

```
// ste user interface
class ste\ste {
	public function __construct($path, $cache = null);

	public function plugin($class_name);

	public function get($name);
	public function show($name);

	public function parse($data, $params);
	public function parse_file($name, $params);

	// ...
}
```

```
$ste = new ste\ste('templates', 'template_cache');
$ste->show('page', array(
	'var1' => 'value1',
	'var2' => 2,
	'var3' => array(1, 2, 3, 4),
));
```


## Cache interface ##

To avoid parsing the templates each time, STE supports a cache system for templates.
This cache system has the following interface:

```
interface ste\cache {
	public function is_cached($name);
	public function store($name, $data);
	public function execute($name, &$params);
}
```

And the code using the interface is:

```
if (!$cache->is_cached($name)) {
	$cache->store($name, process_data());
}
$cache->execute($name);
```

There are two embedded implementations of the cache interface:
```
class ste\cache_null { function __construct() {} }
class ste\cache_file { function __construct($path) {} }
```

They are used automatically in ste\ste class if a string or null passed to the $cache parameter.

## Plugin interface ##

The plugin interface allows extending STE.
Currently only supports adding new tags.
Using reflection the plugin function detects methods starting by TAG_and includes them to the list. If it defines a tag already defined, the last plugin call, will overwrite all the previous._

```
class my_ste_plugin {
	static public function TAG_test(ste\node $node) {
		$this->checkParams(false, array(
			// ...
		));
		// ...
	}
}

$ste->plugin('my_ste_plugin');
```

For more information creating plugins, see the class ste\plugin\_base.

```
// ste plugin interface
class ste\ste {
	public $path;
	public $cache;
	public $plugins = array();
	public $tags    = array();
	public $blocks  = array();
}

class ste\node {
	public $is_root = false;
	public $node_parser, $ste;
	public $data, $name = 'unknown', $line = -1, $params = array();
	public $a = '', $b = array(), $c = '';

	public function setref($that);
	public function add($v);
	public function __construct(node_parser $node_parser);
	public function __tostring();
	public function literal();
	public function emptytag();
	public function createnode();
	public function checkParams($allow_more = true, $params_check = array());
}

class ste\node_parser {
	public $node_root;
	public $ste;
}

```

# Template examples #

**base.php**

_This is the skeleton of the page. All the pages will inherit this one. It defines the **title** block, and the **contents** block. Those blocks would be overrided._
```
<html>
	<head>
		<title>{t}{block id="title"}Default title{/block}{/t}</title>
	</head>
	<body>
		<div id="header">
			<ul id="navbar">
				<li>Section1</li>
				<li>Section2</li>
				<li>Section3</li>
			</ul>
		</div>
		<div id="contents">{block id="contents"}
			Empty page
		{/block}</div>
		<div id="footer">
			&copy; 2009
		</div>
	</body>
</html>
```

**2cols.php**

_This is a basic template overriding **base** and defining two new blocks: **column\_left** and **column\_right** for sections with two columns._
```
{!extends name="base"}

{block id="title"}Two columns title{/block}
{block id="contents"}
	<div id="column_left">{block id="column_left"}
		Left column
	{/block}</div>
	<div id="column_right">{block id="column_right"}
		Right column
	{/block}</div>
{/block}
```

**3cols.php**

_TODO_
```
{!extends name="base"}

{block id="title"}Three columns title{/block}
{block id="contents"}
	<div id="column_left">{block id="column_left"}
		Left column
	{/block}</div>
	<div id="column_middle">{block id="column_middle"}
		Middle column
	{/block}</div>
	<div id="column_right">{block id="column_right"}
		Right column
	{/block}</div>
{/block}
```

**section1.php**

_TODO_
```
{!extends name="2cols"}

{block id="title"}Overrided title for section1{/block}

{block id="column_left"}
	This is the left column
	for section1.
	<ol>
		<li>test1</li>
		<li>test2</li>
		<li>test3</li>
	</ol>
{/block}

```

**section2.php**

_TODO_
```
{!extends name="3cols"}

{block id="column_left"}
	This is the left column
	for section1.
	<ol>
		<li>test1</li>
		<li>test2</li>
		<li>test3</li>
	</ol>
{/block}

{block id="column_right"}
	<ol>
		<li>right1</li>
		<li>right2</li>
	</ol>
{/block}
```