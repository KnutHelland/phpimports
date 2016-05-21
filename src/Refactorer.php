<?php

namespace PhpImports;

use PhpParser\NodeTraverser;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\ParserFactory;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter;


class UseRemover extends NodeVisitorAbstract
{
    private $aliasesToRemove;

    public function __construct(array $aliasesToRemove)
    {
        $this->aliasesToRemove = $aliasesToRemove;
    }

    public function enterNode(Node $node)
    {
        switch ($node->getType()) {
            case 'Stmt_Use':
                if (count($node->uses) == 1) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }
                break;
        }
    }

    public function leaveNode(Node $node)
    {
        switch ($node->getType()) {
            case 'Stmt_Use':

                if (count($node->uses) == 1) {
                    if (in_array($node->uses[0]->alias, $this->aliasesToRemove)) {
                        return NodeTraverser::REMOVE_NODE;
                    }
                }
                break;

            case 'Stmt_UseUse':
                if (in_array($node->alias, $this->aliasesToRemove)) {
                    return NodeTraverser::REMOVE_NODE;
                }
                break;
        }
    }
}

class SortUse extends NodeVisitorAbstract
{
    public function enterNode(Node $node)
    {
        if ($node->getType() == 'Stmt_Namespace') {

            $firstUseIndex;
            $useStmts = [];
            $indexes = [];

            foreach ($node->stmts as $i => $stmt) {
                if ($stmt->getType() == 'Stmt_Use') {
                    if (is_null($firstUseIndex)) {
                        $firstUseIndex = $i;
                    }

                    $useStmts[] = $stmt;
                    $indexes[] = $i;
                }
            }

            if (count($useStmts)) {
                // Remove the statements
                $node->stmts = array_filter($node->stmts, function($node) {
                    return $node->getType() != 'Stmt_Use';
                });

                // Sort statements
                usort($useStmts, function($a, $b) {
                    $aParts = count($a->uses[0]->name->parts);
                    $bParts = count($b->uses[0]->name->parts);

                    if ($aParts != $bParts) {
                        return $aParts > $bParts ? 1 : -1;
                    }

                    $aName = $a->uses[0]->name->toString();
                    $bName = $b->uses[0]->name->toString();

                    return ($aName < $bName) ? -1 : 1;
                });

                // Insert them again
                array_splice($node->stmts, $firstUseIndex, 0, $useStmts);
            }

        }
    }
}

class Statistics extends NodeVisitorAbstract
{
    private $lastUse;

    public function enterNode(Node $node)
    {
        if ($node->getType() == 'Stmt_Namespace') {

            foreach ($node->stmts as $i => $stmt) {
                if ($stmt->getType() == 'Stmt_Use') {
                    $this->lastUse = $stmt;
                }
            }
        }
    }

    public function getLastUseLine()
    {
        if (!$this->lastUse) {
            return 0;
        }

        return $this->lastUse->getLine();
    }
}

class AddUse extends NodeVisitorAbstract
{
    private $node;

    public function __construct($name)
    {
        $name = new Name($name);

        $useUse = new UseUse($name);

        $this->node = new Use_([ $useUse ]);
    }

    public function enterNode(Node $node)
    {
        if ($node->getType() == 'Stmt_Namespace') {

            array_unshift($node->stmts, $this->node);
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;

        }
    }
}


class Refactorer
{
    private $ast;

    private $statistics;

    private $prettyPrinter;

    private $sourceCode;

    public function __construct($sourceCode, array $ast)
    {
        $this->ast = $ast;
        $this->sourceCode = $sourceCode;
        $this->statistics = $this->generateStatistics();
        $this->prettyPrinter = new PrettyPrinter\Standard;
    }

    public function removeUses(array $aliasesToRemove)
    {
        $visitor = new UseRemover($aliasesToRemove);

        $this->traverse([ $visitor ]);
    }

    public function addUse($name)
    {
        $visitor = new AddUse($name);

        $this->traverse([ $visitor ]);
    }

    public function sortImports()
    {
        $visitor = new SortUse();

        $this->traverse([ $visitor ]);
    }

    private function generateStatistics()
    {
        $visitor = new Statistics();

        $this->traverse([ $visitor ]);

        return $visitor;
    }

    private function traverse($visitors)
    {
        $traverser = new NodeTraverser;

        foreach ($visitors as $visitor) {
            $traverser->addVisitor($visitor);
        }

        $this->ast = $traverser->traverse($this->ast);
    }

    public function rewriteTopSection()
    {
        $lines = explode("\n", $this->sourceCode);
        $rest = array_slice($lines, $this->statistics->getLastUseLine() + 1);

        // Remove empty lines in beginning of $rest
        $emptyLines = 0;
        foreach ($rest as $line) {
            if (trim($line) == '') {
                $emptyLines++;
            } else {
                break;
            }
        }
        $rest = array_slice($rest, $emptyLines);

        $rewritten = $this->prettyPrinter->prettyPrintFile($this->ast);
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        $newAst = $parser->parse($rewritten);

        $stats = new Statistics();

        $traverser = new NodeTraverser;
        $traverser->addVisitor($stats);
        $traverser->traverse($newAst);

        $linesNewSource = explode("\n", $rewritten);
        $head = array_slice($linesNewSource, 0, $stats->getLastUseLine());

        return implode("\n", $head) . "\n\n" . implode("\n", $rest);
    }
}
