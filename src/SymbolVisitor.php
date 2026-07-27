<?php

/*
 * This file is part of PapiAI,
 * A simple but powerful PHP library for building AI agents.
 *
 * (c) Marcello Duarte <marcello.duarte@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PapiAI\Ingest\PhpSymbols;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;

/**
 * Walks a parsed file and collects the lines that make up its symbol block.
 *
 * Public API only. The point of `Depth::Api` is the shape of the system, and private helpers are
 * implementation: they cost tokens without telling a reader how the pieces connect.
 */
final class SymbolVisitor extends NodeVisitorAbstract
{
    private ?string $namespace = null;

    /** @var list<string> */
    private array $lines = [];

    /** @var list<string> */
    private array $edges = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Namespace_) {
            $this->namespace = $node->name?->toString();

            return null;
        }

        if ($node instanceof ClassLike) {
            $this->enterClassLike($node);

            return null;
        }

        if ($node instanceof Function_) {
            $this->lines[] = 'function ' . $this->signature($node->name->toString(), $node->params, $node->returnType);
            $this->recordEdges($node->params, $node->returnType);
        }

        return null;
    }

    /**
     * The rendered block, or null when the file declared nothing worth reporting.
     */
    public function block(): ?string
    {
        if ($this->lines === []) {
            return null;
        }

        $head = $this->namespace === null ? [] : ['namespace ' . $this->namespace . ';', ''];
        $tail = [];

        if ($this->edges !== []) {
            $edges = array_values(array_unique($this->edges));
            sort($edges, SORT_STRING);
            $tail = ['-> ' . implode(', ', $edges)];
        }

        return implode("\n", array_merge($head, $this->lines, $tail));
    }

    private function enterClassLike(ClassLike $node): void
    {
        $this->lines[] = $this->declaration($node);

        foreach ($node->getMethods() as $method) {
            if (!$method->isPublic()) {
                continue;
            }

            $this->lines[] = '  ' . $this->signature($method->name->toString(), $method->params, $method->returnType);
            $this->recordEdges($method->params, $method->returnType);
            $this->recordInstantiations($method);
        }

        foreach ($node->getProperties() as $property) {
            $this->recordPropertyEdges($property);
        }
    }

    /**
     * The `class Foo extends Bar implements Baz` line.
     */
    private function declaration(ClassLike $node): string
    {
        $kind = match (true) {
            $node instanceof Interface_ => 'interface',
            $node instanceof Trait_ => 'trait',
            $node instanceof Enum_ => 'enum',
            default => 'class',
        };

        $line = $kind . ' ' . ($node->name?->toString() ?? '(anonymous)');

        if ($node instanceof Class_ && $node->extends !== null) {
            $line .= ' extends ' . $node->extends->getLast();
            $this->edges[] = $node->extends->getLast();
        }

        if ($node instanceof Interface_ && $node->extends !== []) {
            $line .= ' extends ' . $this->nameList($node->extends);
        }

        $implements = $node instanceof Class_ || $node instanceof Enum_ ? $node->implements : [];

        if ($implements !== []) {
            $line .= ' implements ' . $this->nameList($implements);
        }

        return $line;
    }

    /**
     * @param array<int, Param> $params The declared parameters
     */
    private function signature(string $name, array $params, ?Node $returnType): string
    {
        $rendered = array_map(
            function (Param $param): string {
                $type = TypeRenderer::render($param->type);
                $variable = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                    ? '$' . $param->var->name
                    : '$_';

                return trim(($type ?? '') . ' ' . ($param->variadic ? '...' : '') . $variable)
                    . ($param->default !== null ? ' = ...' : '');
            },
            $params,
        );

        $return = TypeRenderer::render($returnType);

        return $name . '(' . implode(', ', $rendered) . ')' . ($return === null ? '' : ': ' . $return);
    }

    /**
     * @param array<int, Param> $params The declared parameters
     */
    private function recordEdges(array $params, ?Node $returnType): void
    {
        foreach ($params as $param) {
            $this->edges = array_merge($this->edges, TypeRenderer::edgesIn($param->type));
        }

        $this->edges = array_merge($this->edges, TypeRenderer::edgesIn($returnType));
    }

    private function recordPropertyEdges(Property $property): void
    {
        $this->edges = array_merge($this->edges, TypeRenderer::edgesIn($property->type));
    }

    /**
     * Record what a method reaches for with `new`, which the signatures alone would miss.
     */
    private function recordInstantiations(ClassMethod $method): void
    {
        foreach ($method->getSubNodeNames() as $subNode) {
            $this->walkForNew($method->{$subNode});
        }
    }

    private function walkForNew(mixed $node): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->walkForNew($child);
            }

            return;
        }

        if (!$node instanceof Node) {
            return;
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            $this->edges = array_merge($this->edges, TypeRenderer::edgesIn($node->class));
        }

        foreach ($node->getSubNodeNames() as $subNode) {
            $this->walkForNew($node->{$subNode});
        }
    }

    /**
     * @param array<int, Name> $names Class or interface names
     */
    private function nameList(array $names): string
    {
        $short = array_map(static fn (Name $name): string => $name->getLast(), $names);
        $this->edges = array_merge($this->edges, $short);

        return implode(', ', $short);
    }
}
