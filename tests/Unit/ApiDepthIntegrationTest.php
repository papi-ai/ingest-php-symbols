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

use PapiAI\Core\HeuristicTokenEstimator;
use PapiAI\Ingest\Depth;
use PapiAI\Ingest\Exception\UnsupportedDepthException;
use PapiAI\Ingest\IngestedFile;
use PapiAI\Ingest\IngestRequest;
use PapiAI\Ingest\NativeIngestor;
use PapiAI\Ingest\PhpSymbols\PhpSymbolExtractor;

/**
 * The reason this package exists: Depth::Api has to work through the ingestor, not just in
 * isolation.
 */
describe('Api depth end to end', function () {
    beforeEach(function () {
        $this->root = makeFixture([
            'src/Order.php' => '<?php
                namespace Shop;

                class Order implements Payable
                {
                    public function total(Money $money): Money
                    {
                        $calculator = new TaxCalculator();

                        return $money;
                    }

                    private function secret(): void
                    {
                    }
                }',
            'src/Money.php' => '<?php namespace Shop; final class Money {}',
            'README.md' => '# Shop',
        ]);
        $this->ingestor = new NativeIngestor(new PhpSymbolExtractor());
    });

    afterEach(function () {
        removeFixture($this->root);
    });

    it('fills symbols for PHP files and leaves the rest alone', function () {
        $digest = $this->ingestor->ingest(new IngestRequest($this->root, Depth::Api));
        $order = current(array_filter($digest->files, fn (IngestedFile $f) => $f->path === 'src/Order.php'));
        $readme = current(array_filter($digest->files, fn (IngestedFile $f) => $f->path === 'README.md'));

        expect($order->symbols)->toContain('class Order implements Payable');
        expect($order->symbols)->toContain('total(Money $money): Money');
        expect($order->symbols)->toContain('TaxCalculator');
        expect($order->symbols)->not->toContain('secret');
        expect($order->content)->toBeNull();
        expect($readme->symbols)->toBeNull();
    });

    it('renders the symbol blocks into the prompt', function () {
        $prompt = $this->ingestor->ingest(new IngestRequest($this->root, Depth::Api))->toPrompt();

        expect($prompt)->toContain('FILE: src/Order.php');
        expect($prompt)->toContain('class Order implements Payable');
        expect($prompt)->toContain('Directory structure:');
    });

    it('costs a fraction of what Full depth costs', function () {
        $estimator = new HeuristicTokenEstimator();
        $api = $estimator->estimateTokens($this->ingestor->ingest(new IngestRequest($this->root, Depth::Api))->toPrompt());
        $full = $estimator->estimateTokens($this->ingestor->ingest(new IngestRequest($this->root, Depth::Full))->toPrompt());

        expect($api)->toBeLessThan($full);
    });

    it('still throws when the ingestor was built without an extractor', function () {
        expect(fn () => (new NativeIngestor())->ingest(new IngestRequest($this->root, Depth::Api)))
            ->toThrow(UnsupportedDepthException::class);
    });

    it('survives a file that does not parse, leaving it in the tree without symbols', function () {
        file_put_contents($this->root . '/src/Broken.php', '<?php class { nope');
        $digest = $this->ingestor->ingest(new IngestRequest($this->root, Depth::Api));
        $broken = current(array_filter($digest->files, fn (IngestedFile $f) => $f->path === 'src/Broken.php'));

        expect($broken)->not->toBeFalse();
        expect($broken->symbols)->toBeNull();
    });
});
