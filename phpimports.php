#!/usr/bin/php
<?php
/**
 * Tool to auto insert use statements into PHP
 */

$ignore = get_defined_functions()['internal'];
$ignore = array_merge($ignore, get_declared_classes());
$ignore = array_merge($ignore, get_declared_interfaces());
$ignore = array_merge($ignore, get_declared_traits());
$ignore = array_merge($ignore, array_keys(get_defined_constants()));
$ignore[] = 'parent';
$ignore[] = 'self';

$inputFile = $argv[1];

require dirname(__FILE__).'/vendor/autoload.php';

/**
 * Returns project root path. The root is where composer.json is defined
 */
function findProject($filename) {
	$filename = realpath($filename);

	if ($filename == '/') {
		return null;
	}

	if (file_exists(dirname($filename). '/composer.json' )) {
		return dirname($filename);
	}

	return findProject(dirname($filename));
}

/**
 * Returns the parsed AST
 */
function getSourceTree($src) {
	$lexer = new PHPParser_Lexer;
	$parser = new PHPParser_Parser($lexer);

	try {
		return $parser->parse($src);
	} catch (Exception $e) {
		return null;
	}
}

/**
 * Childs of returned nodes are not evaluated
 */
function getNodesByType($tree, $nodeType) {
	return getNodesByTypes($tree, [ $nodeType ]);
}

function getNodesByTypes($tree, array $nodeTypes) {
	return getNodesByPred($tree, function($node) use ($nodeTypes) {
		foreach ($nodeTypes as $type) {
			if ($node instanceof $type) {
				return true;
			}
		}
		return false;
	});
}

function getNodesByPred($tree, $pred) {
	$nodes = array();

	foreach ($tree as $node) {
		if (is_object($node) && $pred($node)) {
			$nodes[] = $node;
		} else if ($node instanceof Traversable || is_array($node)) {
			$nodes = array_merge($nodes, getNodesByPred($node, $pred));
		}
	}

	return $nodes;
}

/**
 * Traverses the node tree. If the callback returns false on a node,
 * then it will not walk into its children
 */
function traverseNodes($tree, $closure) {
	foreach ($tree as $node) {
		$visitChildren = true;
		if (is_object($node)) {
			if ($closure($node) === false) {
				$visitChildren = false;
			}
		}
		if ($visitChildren && ($node instanceof Traversable || is_array($node))) {
			traverseNodes($node, $closure);
		}
	}
}

function colorGreen($input) {
	return "\033[32m".$input."\033[39m";
}

function dumpNode($tree, $level = 0) {
	foreach ($tree as $key => $node) {
		$k = '';
		if (is_string($key)) {
			$k = $key.' -> ';
		}

		if (is_object($node)) {
			echo str_repeat(" ", $level) . $k . colorGreen(get_class($node)) . "\n";
		} else if (!is_array($node)) {
			echo str_repeat(" ", $level) . $k . print_r($node, true) . "\n";
		} else {
			if (strlen($k) > 0) {
				echo str_repeat(" ", $level) . $k . ":\n";
			}
		}

		if ($node instanceof Traversable || is_array($node)) {
			dumpNode($node, $level+1);
		}
	}
}


$tree = getSourceTree(file_get_contents($inputFile));

function getClassNamesFromNewExpressions($tree) {
	return array_map(
		function($node) {
			return implode('\\', $node->class->parts); },
		getNodesByType($tree, 'PHPParser_Node_Expr_New'));
}

function getClassNamesFromClassInheritance($tree) {
	$names = array_map(
		function ($class) {
			return implode('\\', $class->extends->parts); },
		getNodesByType($tree, 'PHPParser_Node_Stmt_Class'));

	$names = array_merge(
		$names,
		array_reduce(
			getNodesByType($tree, 'PHPParser_Node_Stmt_Class'),
			function ($out, $class) {
				return array_merge(
					$out,
					array_map(
						function($name) {
							return implode('\\', $name->parts); },
						$class->implements)); },
			array()));
	return $names;
}

function getClassNamesFromStaticCalls($tree) {
	return array_map(
		function($call) {
			return implode('\\', $call->class->parts); },
		getNodesByType($tree, 'PHPParser_Node_Expr_StaticCall'));
}

// var_dump(getSourceTree(file_get_contents(__FILE__)));

/* var_dump(dumpNode(getNodesByTypes($tree, array( */
/* 	'PHPParser_Node_Expr_New', */
/* 	'PHPParser_Node_Name' */
/* )))); */

// $names = array_map(function($node) { return implode('\\', $node->parts); }, getNodesByType($tree, 'PHPParser_Node_Name'));
// $names = array_filter($names, function($name) use ($ignore) { return !in_array($name, $ignore); });


// What dependencies are available?
// $config = include findProject($inputFile);
// $classmap = $config['classmap'];




// $names = array_map(function($call) { return implode('\\', $call->class->parts); }, getNodesByType($tree, 'PHPParser_Node_Expr_StaticCall'));
// print_r($names);
// dumpNode($names);

function getClassNamesFromTypeHinting($tree) {
	return array_reduce(
		getNodesByType($tree, 'PHPParser_Node_Param'),
		function($out, $param) {
			if ($param->type instanceof PHPParser_Node_Name) {
				$out[] = implode('\\', $param->type->parts);
			}
			return $out; },
		array());
}


function getClassesFromNamespace($tree, $classmap) {
	$toReturn = array();
	$namespace = getNodesByType($tree, 'PHPParser_Node_Stmt_Namespace');
	if (count($namespace) > 0) {
		$namespace = implode('\\', $namespace[0]->name->parts);
		foreach (array_keys($classmap) as $class) {
			if (substr($class, 0, strlen($namespace)) == $namespace) {
				$toReturn[] = substr($class, strlen($namespace)+1);
			}
		}
	}

	return $toReturn;
}


// What do we depend on?
$names = getClassNamesFromNewExpressions($tree);
$names = array_merge($names, getClassNamesFromClassInheritance($tree));
$names = array_merge($names, getClassNamesFromStaticCalls($tree));
$names = array_merge($names, getClassNamesFromTypeHinting($tree));

// Filter those to ignore
$names = array_unique($names);
$names = array_filter($names, function($name) use ($ignore) { return !in_array($name, $ignore); });
$fromNamespace = getClassesFromNamespace($tree, $classmap);
$names = array_filter($names, function($name) use ($fromNamespace) { return !in_array($name, $fromNamespace); });

/**
 * Returns array:
 *
 * array(
 *     'needed' => array(
 *         'MyClass' => array(
 *             'Vendor/Lib/MyClass',
 *             'Andor/Lab/MyClass)),
 *     'keep' => array(
 *         'SomeClass' => 'Path/To/SomeClass'),
 *     'delete' => array(
 *         'NotUsedAnywhere' => 'Path/to/NotUsedAnywhere'));
 */




// Evaluate the autoload file
$autoloader = include findProject($inputFile) . '/vendor/autoload.php';
$classmap = $autoloader->getClassMap();


/**
 * Returns list of candidates from classmap for each of the names
 */
function getCandidates($names, $classmap) {
	$output = array();

	foreach ($names as $name) {
		$parts = explode('\\', $name);

		$candidates = array();

		foreach(array_keys($classmap) as $class) {

			$classParts = explode('\\', $class);
			if (count($classParts) <= 1) {
				// Skip classes in global namespace
				continue;
			}


			$matches = true;
			for ($i = count($parts); $i > 0 ; $i--) {
				if ($classParts[ (count($classParts) - $i) ] != $parts[count($parts) - $i]) {
					$matches = false;
					break;
				}
			}

			if ($matches) {
				if ((count($classParts) - count($parts) + 1) > 1) {
					$candidates[] = implode('\\', array_slice($classParts, 0, count($classParts) - count($parts) + 1));
				}
			}
		}

		$output[$name] = $candidates;
	}
	return $output;
}


$names = getCandidates($names, $classmap);
$namesWithCandidates = $names;

$names = array_map(
	function($name) { return $name[0]; },
	$names);


// And what is already used?
$uses = array_reduce(getNodesByType($tree, 'PHPParser_Node_Stmt_Use'), function($output, $use) {
	foreach ($use->uses as $u) {
		$output[] = implode('\\', $u->name->parts);
	}
	return $output;
}, array());
// print_r($uses);

class InvalidSourceException extends Exception {}

/**
 * Want to validate that all use-statements comes after each other,
 * and that there is maximum one namespace in the file, and that the
 * namespace is the first statement in the file.
 */
function validateFile($tree) {
	$namespaces = getNodesByType($tree, 'PHPParser_Node_Stmt_Namespace');
	if (count($namespaces) == 1) {
		if ($namespaces[0] != $tree[0]) {
			throw new InvalidSourceException('The namespace is not declared in top of file');
		}
	} else if (count($namespaces) > 1) {
		throw new InvalidSourceException('Multiple namespaces in file');
	}

	$data = array(
		'beginUses' => null,
		'beginLine' => null,
		'endUses' => null,
		'endLine' => null
	);
	$i = 0;
	traverseNodes($tree, function($node) use (&$data, &$i) {
		$return = true;
		if ($node instanceof PHPParser_Node_Stmt_Use) {
			if (is_null($data['beginUses'])) {
				$data['beginUses'] = $i;
				$data['beginLine'] = $node->getAttribute('startLine');
			} else if (!is_null($data['endUses'])) {
				throw new InvalidSourceException('The use statements are not in one section');
			}
			$return = false;
			$data['endLine'] = $node->getAttribute('startLine') + 1;
		} else {
			if (!is_null($data['beginUses']) && is_null($data['endUses'])) {
				$data['endUses'] = $i-1;
				// $data['endLine'] = $node->getAttribute('startLine')-1;
			}
		}
		$i++;
		return $return;
	});

	return array(
		'begin' => $data['beginLine'],
		'end' => $data['endLine']
	);
}

// dumpNode($tree); exit();
// print_r(validateFile($tree)); exit();

$toUse = array_diff($names, $uses);

$output = array();
foreach ($toUse as $use) {
	$output[] = 'use '.$use.";\n";
}

$line = 2;
$uses = getNodesByType($tree, 'PHPParser_Node_Stmt_Use');
$uses = array_map(
	function($node) {
		return implode('\\', $node->uses[0]->name->parts); },
	$uses);


foreach (array_keys($namesWithCandidates) as $name) {
	$parts = explode('\\', $name);

	$candidates = array();

	foreach($uses as $class) {

		$classParts = explode('\\', $class);
		if (count($classParts) <= 1) {
			// Skip classes in global namespace
			continue;
		}


		$matches = true;
		for ($i = count($parts); $i > 0 ; $i--) {
			if ($classParts[ (count($classParts) - $i) ] != $parts[count($parts) - $i]) {
				$matches = false;
				break;
			}
		}

		if ($matches) {
			$candidates[] = implode('\\', array_slice($classParts, 0, count($classParts) - count($parts) + 1));
		}
	}

	if (count($candidates) > 0) {
		$namesWithCandidates[$name] = $candidates[0];
	}
}

// Select most appropriate candidates:
foreach ($namesWithCandidates as $name => &$candidate) {
	if (is_array($candidate)) {
		if (count($candidate) == 0) {
			$candidate = '';
		} else {
			$candidate = $candidate[0];
		}
	}
}
sort($namesWithCandidates);
// print_r($namesWithCandidates); exit();


/* if (count($uses) > 0) { */
/* 	$line = $uses[0]->getAttribute('startLine') - 1; */
/* } */

$output = '';
foreach ($namesWithCandidates as $name) {
	if (strlen($name) > 0) {
		$output .= 'use '.$name.";\n";
	}
}

$src = file($inputFile);
$data = validateFile($tree);
if (is_null($data['begin'])) {
	$data['begin'] = $data['end'] = 3;
}
array_splice($src, $data['begin']-1, ($data['end'] - $data['begin']), $output);

if ($argv[2] == '-w') {
	file_put_contents($inputFile, implode('', $src));
} else {
	echo implode('', $src);
}


// dumpNode($tree);

// var_dump(getUseStatements(getSourceTree(file_get_contents(__FILE__))));
