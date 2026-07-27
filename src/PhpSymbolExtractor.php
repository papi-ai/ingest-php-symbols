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

use PapiAI\Ingest\Contracts\SymbolExtractorInterface;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Reduces a PHP file to what it declares and what it reaches for.
 *
 * This is the ontology behind `Depth::Api`: FQCN, kind, extends and implements, public method
 * signatures, and outgoing edges. A repository rendered this way stays legible whole, for a
 * fraction of what its full text would cost.
 *
 * Deterministic: parsing the same bytes always yields the same block, with edges sorted.
 */
final class PhpSymbolExtractor implements SymbolExtractorInterface
{
    private readonly Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function supports(string $path): bool
    {
        return str_ends_with(strtolower($path), '.php');
    }

    public function extract(string $path, string $source): ?string
    {
        if (!$this->supports($path)) {
            return null;
        }

        try {
            $ast = $this->parser->parse($source);
        } catch (Error) {
            // A file that does not parse still belongs in the tree; it just has no symbols to show.
            return null;
        }

        if ($ast === null) {
            return null;
        }

        $visitor = new SymbolVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->block();
    }
}
