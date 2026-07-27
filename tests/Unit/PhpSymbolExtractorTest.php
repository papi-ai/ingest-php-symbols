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

use PapiAI\Ingest\Contracts\SymbolExtractorInterface;
use PapiAI\Ingest\PhpSymbols\PhpSymbolExtractor;

describe('PhpSymbolExtractor', function () {
    beforeEach(function () {
        $this->extractor = new PhpSymbolExtractor();
        $this->extract = fn (string $source) => $this->extractor->extract('src/Subject.php', $source);
    });

    it('implements the extractor contract', function () {
        expect($this->extractor)->toBeInstanceOf(SymbolExtractorInterface::class);
    });

    it('handles PHP files and nothing else', function () {
        expect($this->extractor->supports('src/Agent.php'))->toBeTrue();
        expect($this->extractor->supports('src/Agent.PHP'))->toBeTrue();
        expect($this->extractor->supports('README.md'))->toBeFalse();
        expect($this->extractor->extract('README.md', '<?php class A {}'))->toBeNull();
    });

    it('reports the namespace once, at the top', function () {
        $block = ($this->extract)('<?php namespace App\Domain; class Order {}');

        expect($block)->toStartWith("namespace App\Domain;\n\nclass Order");
    });

    it('reports what a class extends and implements', function () {
        $block = ($this->extract)('<?php class Order extends Base implements Jsonable, Arrayable {}');

        expect($block)->toContain('class Order extends Base implements Jsonable, Arrayable');
    });

    it('reports public method signatures with types', function () {
        $block = ($this->extract)('<?php class Order {
            public function total(Money $m, ?int $round = 2): Money {}
        }');

        expect($block)->toContain('total(Money $m, ?int $round = ...): Money');
    });

    it('leaves private and protected methods out, they are implementation', function () {
        $block = ($this->extract)('<?php class Order {
            public function pay() {}
            protected function calculate() {}
            private function log() {}
        }');

        expect($block)->toContain('pay()');
        expect($block)->not->toContain('calculate');
        expect($block)->not->toContain('log');
    });

    it('marks variadic parameters', function () {
        $block = ($this->extract)('<?php class Bus { public function send(Message ...$messages) {} }');

        expect($block)->toContain('send(Message ...$messages)');
    });

    it('renders union and intersection types', function () {
        $block = ($this->extract)('<?php class A {
            public function u(int|string $v): Money|null {}
            public function i(Countable&Traversable $v) {}
        }');

        expect($block)->toContain('u(int|string $v): Money|null');
        expect($block)->toContain('i(Countable&Traversable $v)');
    });

    it('names interfaces, traits and enums for what they are', function () {
        expect(($this->extract)('<?php interface Payable {}'))->toContain('interface Payable');
        expect(($this->extract)('<?php trait Timestamps {}'))->toContain('trait Timestamps');
        expect(($this->extract)('<?php enum Status: string {}'))->toContain('enum Status');
    });

    it('reports an interface extending others', function () {
        expect(($this->extract)('<?php interface A extends B, C {}'))->toContain('interface A extends B, C');
    });

    it('reports top-level functions', function () {
        expect(($this->extract)('<?php function helper(Config $c): void {}'))
            ->toContain('function helper(Config $c): void');
    });

    describe('edges', function () {
        it('records what the class reaches for, sorted and deduplicated', function () {
            $block = ($this->extract)('<?php class A implements Z {
                public function run(B $b): C {}
                public function again(B $b): C {}
            }');

            expect($block)->toContain('-> B, C, Z');
        });

        it('records instantiations the signatures would miss', function () {
            $block = ($this->extract)('<?php class A { public function run() { $x = new Hidden(); } }');

            expect($block)->toContain('Hidden');
        });

        it('records typed properties', function () {
            $block = ($this->extract)('<?php class A { public Logger $logger; }');

            expect($block)->toContain('-> Logger');
        });

        it('ignores scalars and self references, which say nothing about wiring', function () {
            $block = ($this->extract)('<?php class A {
                public function run(int $a, string $b, array $c, bool $d): static {}
            }');

            expect($block)->not->toContain('->');
        });
    });

    describe('files with nothing to say', function () {
        it('returns null when the file declares nothing', function () {
            expect(($this->extract)('<?php $x = 1; echo $x;'))->toBeNull();
        });

        it('returns null rather than throwing when the file does not parse', function () {
            expect(($this->extract)('<?php class { this is not php'))->toBeNull();
        });
    });

    it('is deterministic', function () {
        $source = '<?php namespace N; class A implements Z { public function r(B $b): C { $d = new D(); } }';

        expect(($this->extract)($source))->toBe(($this->extract)($source));
    });
});
