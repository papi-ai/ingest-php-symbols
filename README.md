# papi-ai/ingest-php-symbols

PHP symbol extraction for [PapiAI](https://papi-ai.org) ingestion: the `Depth::Api` ontology.

Reduces a PHP file to what it declares and what it reaches for, so a model can see the shape of a
whole system without paying for its method bodies.

## Install

```bash
composer require papi-ai/ingest-php-symbols
```

## Usage

Pass it to the ingestor and ask for `Depth::Api`:

```php
use PapiAI\Ingest\Depth;
use PapiAI\Ingest\IngestRequest;
use PapiAI\Ingest\NativeIngestor;
use PapiAI\Ingest\PhpSymbols\PhpSymbolExtractor;

$ingestor = new NativeIngestor(new PhpSymbolExtractor());
$digest = $ingestor->ingest(new IngestRequest('/path/to/repo', Depth::Api, tokenBudget: 4000));

echo $digest->toPrompt();
```

## What a block looks like

`papi-ai/ingest`'s own `NativeIngestor.php`, 1,500 lines of file reduced to this:

```
namespace PapiAI\Ingest;

class NativeIngestor implements RepositoryIngestorInterface
  __construct(?SymbolExtractorInterface $extractor = ..., TokenEstimatorInterface $estimator = ...)
  supports(IngestRequest $request): bool
  ingest(IngestRequest $request): RepositoryDigest
-> HeuristicTokenEstimator, IngestRequest, PathFilter, RepositoryDigest, RepositoryIngestorInterface,
   SymbolExtractorInterface, TokenEstimatorInterface, TreeBuilder
```

The `->` line is the point. Signatures tell you what a class accepts; the edges tell you what it is
built out of, including types it only ever instantiates internally, which no signature would reveal.

Measured across `papi-ai/ingest`'s own source: **486 tokens at `Api` against 8,763 at `Full`, or
5.5%**. A repository that would not fit in a context window at all fits comfortably, whole.

## What it reports

- Namespace, once, at the top.
- Every class, interface, trait and enum, with what it extends and implements.
- **Public** method signatures only, with parameter and return types, variadics marked and defaults
  shown as present rather than spelled out. Private and protected methods are implementation: they
  cost tokens without telling a reader how the pieces connect.
- Top-level functions.
- Outgoing edges: types in signatures, typed properties, and classes reached with `new`. Sorted,
  deduplicated, with scalars and `self`/`static`/`parent` left out.

Types print as they were written in the source, not fully qualified, because `RepositoryDigest`
says as much as `PapiAI\Ingest\RepositoryDigest` to anyone reading the file it came from, for a
quarter of the tokens.

## Behaviour

Deterministic: the same bytes always give the same block. A file that does not parse yields no
block rather than an exception, so it still appears in the tree with its path and size intact.

## License

MIT, Marcello Duarte
