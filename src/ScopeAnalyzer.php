<?php

namespace PhpImports;

use PhpParser\NodeTraverser;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class ExtractNames extends NodeVisitorAbstract
{
    /**
     * List of imported names
     */
    public $uses;

    /**
     * List of names
     */
    public $names;

    public function enterNode(Node $node)
    {
        if ($node->getType() == 'Stmt_Use') {
            foreach ($node->uses as $use) {
                $this->uses[] = $use;
            }
        }

        // echo $node->getType() . "\n";

        if (isset($node->class) && $node->class) {
            if ($node->class->getType() != 'Name_FullyQualified') {
                $this->names[] = $node->class;
            }
        }

        if (isset($node->extends) && $node->extends) {
            if ($node->extends->getType() != 'Name_FullyQualified') {
                $this->names[] = $node->extends;
            }
        }

        if (isset($node->implements) && $node->implements) {
            foreach ($node->implements as $implements) {
                if ($implements->getType() != 'Name_FullyQualified') {
                    $this->names[] = $implements;
                }
            }
        }

        if (isset($node->params)) {
            foreach ($node->params as $param) {
                if ($param->type && !is_string($param->type)) {
                    if ($param->type->getType() != 'Name_FullyQualified') {
                        $this->names[] = $param->type;
                    }
                }
            }
        }
    }
}

class ScopeAnalyzer
{
    /**
     * AST of the whole file
     *
     * @var
     */
    private $ast;

    private $visitor;

    public function __construct(array $ast)
    {
        $this->ast = $ast;

        $this->visitor = new ExtractNames;
        $traverser = new NodeTraverser;
        $traverser->addVisitor($this->visitor);
        $traverser->traverse($this->ast);
    }

    /**
     * Returns list of unresolved names
     *
     * @return Name[]
     */
    public function getUnresolvedNames()
    {
        $names = $this->visitor->names;

        $aliases = array_map(function ($use) {
            return $use->alias;
        }, $this->visitor->uses);

        $names = array_filter($this->visitor->names, function ($name) use ($aliases) {
            return !in_array($name->parts[0], $aliases);
        });

        return $names;
    }

    /**
     * Returns a list of the unused uses nodes
     *
     * @return Use_[]
     */
    public function getUnusedUses()
    {
        $names = array_map(function ($name) {
            return $name->parts[0];
        }, $this->visitor->names);

        return array_filter($this->visitor->uses, function ($use) use ($names) {
            return !in_array($use->alias, $names);
        });
    }
}
