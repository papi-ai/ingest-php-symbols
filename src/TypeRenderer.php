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
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;

/**
 * Renders php-parser type nodes back to the text a reader recognises.
 *
 * Types print as they were written in the source rather than fully qualified. A symbol block is
 * paid for in tokens, and `RepositoryDigest` says as much as `PapiAI\Ingest\RepositoryDigest` to
 * anyone reading the file it came from.
 */
final class TypeRenderer
{
    /**
     * Types that say nothing about how the code is wired together.
     *
     * @var list<string>
     */
    private const NOT_AN_EDGE = [
        'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never',
        'null', 'object', 'parent', 'self', 'static', 'string', 'true', 'void',
    ];

    /**
     * Render a type node as source-shaped text.
     *
     * @param Node|null $type The type node, or null when none was declared
     *
     * @return string|null The rendered type, or null when there was none
     */
    public static function render(?Node $type): ?string
    {
        return match (true) {
            $type instanceof Identifier => $type->toString(),
            $type instanceof Name => $type->getLast(),
            $type instanceof NullableType => '?' . self::render($type->type),
            $type instanceof UnionType => self::join($type, '|'),
            $type instanceof IntersectionType => self::join($type, '&'),
            default => null,
        };
    }

    /**
     * Pull the class names out of a type, ignoring anything built in.
     *
     * @param Node|null $type The type node to inspect
     *
     * @return list<string> Class names worth recording as an edge
     */
    public static function edgesIn(?Node $type): array
    {
        if ($type instanceof NullableType) {
            return self::edgesIn($type->type);
        }

        if ($type instanceof ComplexType) {
            $edges = [];

            foreach ($type->types ?? [] as $inner) {
                $edges = array_merge($edges, self::edgesIn($inner));
            }

            return $edges;
        }

        $rendered = self::render($type);

        if ($rendered === null || in_array(strtolower($rendered), self::NOT_AN_EDGE, true)) {
            return [];
        }

        return [$rendered];
    }

    /**
     * @param UnionType|IntersectionType $type      The composite type to flatten
     * @param string                     $separator Character joining its members
     */
    private static function join(UnionType|IntersectionType $type, string $separator): string
    {
        return implode($separator, array_filter(array_map(
            static fn (Node $inner): ?string => self::render($inner),
            $type->types,
        )));
    }
}
