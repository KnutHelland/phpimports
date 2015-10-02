<?php

class HelloHey {

}

require 'vendor/autoload.php';

$parser = new PHPParser_Parser(new PHPParser_Lexer);

function HelloWorld() {

}

$scopes = array();

function get_stmts($stmt) {
	$stmts = array();

	foreach ($stmt as $key => $val) {
		if ($key == 'stmts') {
			$stmts = $val;
			return $stmts;
		}
	}
	return $stmts;
}

function parse_stmts($stmts) {
	$output = array();

	$i = 0;

	foreach ($stmts as $key => $stmt) {
		$k = $key;
		if (is_integer($key)) {
			$k = $i++;
		}

		if (is_array($stmt)) {
			$output[$k] = parse_stmts($stmt);
			continue;
		}

		$type = @get_class($stmt);
		if (empty($stmt)) {
			$type = 'NULL';
		} else if (!$type) {
			$type = print_r($stmt, true);
		}
		
		if ($stmt instanceof PHPParser_Node_Stmt) {
			$output[$k . '  '. $i . ' STATEMENT  ' . $type] = parse_stmts($stmt);
		} else if ($stmt instanceof PHPParser_Node_Expr) {
			$output[$k] = 'EXPRESSION ' . $type;
		} else if ($stmt instanceof PHPParser_Node_Name) {
			$output[$k] = 'NAME       ' . $type;
		} else {
			$output[$k] = 'UNKNOWN    ' . $type;
		}
	}

	return $output;
}


class MyNodeVisitor extends PHPParser_NodeVisitorAbstract
{
    public function leaveNode(PHPParser_Node $node) {
        if ($node instanceof PHPParser_Node_Scalar_String) {
            $node->value = 'foo';
        }
		print_r($node);
    }
}

try {
	$stmts = $parser->parse(file_get_contents(__FILE__));
	// print_r(parse_stmts($stmts));

	$traverser = new PHPParser_NodeTraverser();
	$traverser->addVisitor(new MyNodeVisitor);
	$stmts = $traverser->traverse($stmts);

	// print_r($stmts);
} catch (PHPParser_Error $e) {
	echo 'Parser error: ', $e->getMessage();
}