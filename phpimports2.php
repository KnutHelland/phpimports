#!/usr/bin/php
<?php
/**
 * Tool to auto insert use statements into PHP
 */

use PhpImports\ScopeAnalyzer;
use PhpImports\Refactorer;
use PhpImports\DependencyResolver;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter;

require dirname(__FILE__).'/vendor/autoload.php';



/*
|---------------------------------------------------------------------------
| Helper functions
|---------------------------------------------------------------------------
|
|
|
*/

if (!function_exists('dd')) {
    function dd()
    {
        array_map(function ($x) {
            var_dump($x);
        }, func_get_args());
        die(1);
    }
}

if (!function_exists('pp')) {
    function pp($nodes)
    {
        static $prettyPrinter;

        if (!$prettyPrinter) {
            $prettyPrinter = new PrettyPrinter\Standard;
        }

        if (!is_array($nodes)) {
            $nodes = func_get_args();
        }

        echo $prettyPrinter->prettyPrint($nodes) . "\n";
        die(1);
    }
}

/*
|---------------------------------------------------------------------------
| Setup
|---------------------------------------------------------------------------
|
|
|
*/

$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);


/*
|---------------------------------------------------------------------------
| Generate abstract syntaxt tree
|---------------------------------------------------------------------------
|
|
|
*/

$inputFile = $argv[1];
$sourceCode = file_get_contents($inputFile);
$ast = $parser->parse($sourceCode);


/*
|---------------------------------------------------------------------------
| Analyze the tree
|---------------------------------------------------------------------------
|
|
|
*/


$analyzed = new ScopeAnalyzer($ast);
$resolver = new DependencyResolver($inputFile);

$resolutions = $resolver->resolve($analyzed->getUnresolvedNames());

$refactorer = new Refactorer($sourceCode, $ast);

$useAliases = array_map(function($useUse) {
    return $useUse->alias;
}, $analyzed->getUnusedUses());

$refactorer->removeUses($useAliases);

foreach ($resolutions as $use) {
    $refactorer->addUse($use);
}

$refactorer->sortImports();

echo $refactorer->rewriteTopSection();
