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

$inputFile = $argv[1];

require dirname(__FILE__).'/vendor/autoload.php';

function findProject($filename) {
	$filename = realpath($filename);
	if ($filename == '/') {
		return null;
	}
	if (file_exists(dirname($filename).'/.phpimports')) {
		return dirname($filename).'/.phpimports';
	}
	return findProject(dirname($filename));
}


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
 * Returns all use statements
 *
 * This cannot use getNodesByType because we only want use-statements
 * from the first level. The other use statements are "use trait".
 */
function getUseStatements($tree) {
	return array_filter($tree, function($node) { return $node instanceof PHPParser_Node_Stmt_Use; });
}

/**
 * Childs of returned nodes are not evaluated
 */
function getNodesByType($tree, $nodeType) {
	return getNodesByTypes($tree, array($nodeType));
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


function dumpNode($tree, $level = 0) {
	foreach ($tree as $key => $node) {
		$k = '';
		if (is_string($key)) {
			$k = $key.' -> ';
		}

		if (is_object($node)) {
			echo str_repeat(" ", $level) . $k . get_class($node) . "\n";
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

// var_dump(getSourceTree(file_get_contents(__FILE__)));

/* var_dump(dumpNode(getNodesByTypes($tree, array( */
/* 	'PHPParser_Node_Expr_New', */
/* 	'PHPParser_Node_Name' */
/* )))); */

// $names = array_map(function($node) { return implode('\\', $node->parts); }, getNodesByType($tree, 'PHPParser_Node_Name'));
// $names = array_filter($names, function($name) use ($ignore) { return !in_array($name, $ignore); });


// What dependencies are available?
$config = include findProject($inputFile);
$classmap = $config['classmap'];


// $names = array_map(function($call) { return implode('\\', $call->class->parts); }, getNodesByType($tree, 'PHPParser_Node_Expr_StaticCall'));
// print_r($names);
// dumpNode($names);

// What do we depend on?
$names = getClassNamesFromNewExpressions($tree);
$names = array_filter($names, function($name) use ($ignore) { return !in_array($name, $ignore); });

$names = array_merge($names, array_map(function ($class) { return implode('\\', $class->extends->parts); }, getNodesByType($tree, 'PHPParser_Node_Stmt_Class')));
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
$names = array_merge($names, array_map(function($call) { return implode('\\', $call->class->parts); }, getNodesByType($tree, 'PHPParser_Node_Expr_StaticCall')));

// Only which exists in the classmap:
$names = array_reduce($names, function($output, $name) use ($classmap) {

	if (!substr($name, 0, 1) == '\\') {
		$name = '\\'.$name;
	}

	foreach(array_keys($classmap) as $class) {
		if (substr($class, 0 - strlen($name)) == $name) {
			if (strstr($class, '\\')) {
				$output[] = $class;
				return $output;
			}
		}
	}
	return $output;

}, array());
$names = array_unique($names);

// And what is already used?
$uses = array_reduce(getUseStatements($tree), function($output, $use) {
	foreach ($use->uses as $u) {
		$output[] = implode('\\', $u->name->parts);
	}
	return $output;
}, array());
// print_r($uses);

$toUse = array_diff($names, $uses);

$output = array();
foreach ($toUse as $use) {
	$output[] = 'use '.$use.";\n";
}

$line = 2;
$uses = getUseStatements($tree);
if (count($uses) > 0) {
	$line = $uses[0]->getAttribute('startLine') - 1;
}

$src = file($inputFile);
array_splice($src, $line, 0, $output);

if ($argv[2] == '-w') {
	file_put_contents($inputFile, implode('', $src));
} else {
	echo implode('', $src);
}


// dumpNode($tree);

// var_dump(getUseStatements(getSourceTree(file_get_contents(__FILE__))));

