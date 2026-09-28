<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Retrieval;

/**
 * RetrievalIndex — the repository a lane's context is drawn from.
 *
 * WHY THIS EXISTS
 * Before this, retrieval was a directory walk and a term count, repeated on every call, owned by
 * whoever needed it. Measured 2026-09-19: the repository had per-feature file stores
 * (storage/private/comprehension, storage/private/workbench/issues, metrics.json) and a heuristic
 * scorer, but nothing reusable -- no persisted index, no incrementality, and nothing another
 * subsystem could call. So every caller re-walked the tree and scored from scratch, and an "index"
 * never existed to be kept current.
 *
 * THIS IS A CACHE OF THE REPOSITORY'S TEXT, and it is designed around three properties that make it
 * usable rather than merely present:
 *
 *   CURRENT      A document is reindexed only when its content hash changes. `isCurrent()` answers
 *                the question directly, so a caller can trust the index instead of re-reading files,
 *                and a deleted file is REMOVED rather than lingering as a stale hit.
 *   REUSABLE     index / search / forget / stats / isCurrent. No hidden state, no globals, and the
 *                root is injected, so the harness, a CLI and a test all drive the same object.
 *   EXPLAINED    Every hit carries the terms that matched it and the score they produced. A retrieval
 *                result a reader cannot interrogate is a result nobody will trust.
 *   RETIRED      A path can be material from a harness this repository no longer runs. It stays INDEXED
 *                -- the director's instruction was to keep it, because there is still something to learn
 *                from it -- but it is not handed to a lane as context unless a caller asks for it.
 *                Currency cannot do this job: see isRetired() for the measurement of why not.
 *
 * WHY FILES AND NOT A TABLE
 * Every Workbench subsystem already persists under storage/private (comprehension, issues, metrics,
 * ai-cache). A table would need a migration, MySQL 5.7 compatibility care on the deployment target
 * (no CTEs, no window functions), and a transaction to stay consistent with a filesystem it is
 * already describing. At this size -- a repository's source, a few thousand documents -- a single
 * JSON index is faster to build, atomic to write, and impossible to get out of step with the code.
 * The API is the reusable part: the storage behind it is one class, so a table can replace this file
 * without a caller changing.
 *
 * WHAT BREAKS AS THE DATA ACCUMULATES, AND WHAT TO DO ABOUT EACH (the future-proofing, in order of
 * what will bite first):
 *
 *   1. HASHING EVERY FILE ON EVERY CALL. Already addressed -- index() stats before it reads, so an
 *      unchanged tree costs a stat per file rather than a read and a hash. This is the cost that
 *      grows with the repository, and it is the first one to check if indexing slows down.
 *   2. LOADING AND REWRITING ONE JSON DOCUMENT. Fine to a few thousand documents; past that, shard
 *      the index by top-level prefix (kernel/, modules/, docs/, ...) behind the same API, and keep
 *      index.json as a manifest of shards. Nothing outside this class needs to change.
 *   3. RANKING THAT CANNOT LEARN. Lexical scoring cannot tell that `star-swarm.js` is the right answer
 *      because it served the last three tasks. recordUse() gives it that: usage is a BOUNDED boost with
 *      a half-life, so a file that has actually been used outranks one that merely mentions the words,
 *      and a file that served tasks months ago stops outranking them. Unbounded usage credit would be
 *      worse than none: the ranker would converge on what it already recommended and never recover.
 *   4. VOCABULARY THAT DOES NOT MATCH THE QUESTION. Lexical search fails when the caller asks in
 *      different words than the code uses. search() reports `confidence`, and that is the decision
 *      point for a hybrid: LOW means the local index found nothing useful and is the only honest time
 *      to reach for an online or embedding-backed retriever. Local stays the default because code is
 *      identifier-shaped -- exact names are what a searcher wants -- and because it is private,
 *      offline and free. Online is a fallback for concepts, never the first call.
 *
 * @phpstan-type Entry array{hash:string, mtime:int, size:int, lines:int, terms:array<string,int>, uses:int, last_used_at:int, indexed_at:string}
 * @phpstan-type Hit array{path:string, score:int, lines:int, matched:list<string>, uses:int}
 */
final class RetrievalIndex
{
    /** Words too common to discriminate. Kept in one place so indexing and querying agree. */
    private const STOPWORDS = [
        'the' => 1, 'and' => 1, 'for' => 1, 'with' => 1, 'that' => 1, 'this' => 1, 'from' => 1,
        'into' => 1, 'when' => 1, 'than' => 1, 'then' => 1, 'them' => 1, 'they' => 1, 'there' => 1,
        'must' => 1, 'not' => 1, 'are' => 1, 'was' => 1, 'were' => 1, 'its' => 1, 'has' => 1,
        'have' => 1, 'been' => 1, 'will' => 1, 'would' => 1, 'should' => 1, 'could' => 1, 'each' => 1,
        'also' => 1, 'only' => 1, 'more' => 1, 'most' => 1, 'such' => 1, 'same' => 1, 'because' => 1,
        'about' => 1, 'which' => 1, 'where' => 1, 'what' => 1, 'does' => 1, 'done' => 1,
    ];

    /** Extensions worth indexing. A retrieval index that eats binaries is a retrieval index that lies. */
    private const INDEXABLE = ['php', 'js', 'ts', 'tsx', 'jsx', 'css', 'disyl', 'json', 'md', 'sql', 'sh', 'html'];

    /**
     * Paths whose material is RETIRED -- the harness this repository no longer runs.
     *
     * PREFIXES, not paths: what was retired is the harness's whole directory, and listing 242 files
     * would be a snapshot that the next file added to it silently escapes. The authoritative list is in
     * tools/RETIRED.md; its retrieval enforcement is centralised here rather than repeated at call sites.
     *
     * A prefix ending in `/` retires a DIRECTORY and everything under it. A prefix that names a file
     * retires exactly that file: `tools/ai-run.php` must not swallow `tools/ai-run.php.bak`, which is a
     * different artifact. That distinction is enforced in isRetired(), not assumed here.
     *
     * The three drivers were retired by decision `retired-tool-authority-20260919-153252` (option c),
     * which is applied in tools/RETIRED.md; the L0-L4 vocabulary they used is still cited by policy and
     * is not retired with the driver.
     */
    private const RETIRED_PREFIXES = [
        'tools/harpp2/',
        'tools/ai-run.php',
        'tools/ai-project.php',
        'tools/ai-autonomy.php',
    ];

    private const VERSION = 1;

    /**
     * Default breadth of a search, in hits.
     *
     * A single document is not a context set, and a caller that asks once should not have to know the
     * right limit to get a usable answer. Eight is the top of the band that stays actionable: measured
     * 2026-09-19, the previous default of twelve filled its extra four slots with documents that matched
     * only common words (`kernel/App.php` for a browser-game objective), and breadth a caller cannot act
     * on is not context. Distinctness is delivered by the spread rule below, not by a bigger number.
     */
    public const DEFAULT_LIMIT = 8;

    /** A term found in the path is worth this many body occurrences (which stop counting at the cap). */
    private const PATH_TERM_BONUS = 8;

    /** Body occurrences of one term stop adding score here, so repetition cannot beat coverage. */
    private const BODY_TERM_CAP = 4;

    /**
     * At most this many hits per top-level area in one result.
     *
     * A lane handed eight near-copies of one directory has been given one answer, not eight. Measured
     * 2026-09-19: the moon query returned three files under `tests/browser`-adjacent areas and pushed the
     * file that actually draws the creature below the cut. Two per area keeps the top of the list about
     * the areas that answer the question; the displaced hits are backfilled, so nothing is lost.
     */
    private const SPREAD_QUOTA = 2;

    /** Ceiling on the ranking credit usage can earn a document, whatever its history. */
    public const USE_BOOST_MAX = 6;

    /** Days after which usage credit halves. */
    public const USE_HALF_LIFE_DAYS = 14;

    private string $indexPath;

    /** Cached answer to "does this checkout carry the ignore rules the walk must honour?". */
    private ?bool $gitAvailable = null;

    /**
     * @param string      $root            where the index itself lives
     * @param string|null $repositoryRoot  the tree the indexed paths are relative to. INJECTED, not
     *                                     inferred: the first version counted three parent directories
     *                                     from the index root, which worked in exactly one location and
     *                                     made the class unusable from anywhere else -- including its own
     *                                     test, where seven controls failed on path arithmetic alone.
     */
    public function __construct(
        private readonly string $root,
        ?string $repositoryRoot = null
    ) {
        // CANONICALISE THE ROOT. Measured 2026-09-19 with `new RetrievalIndex($dir.'/.index', $dir.'/sub/..')`:
        // the stored key became `tmp/norm-.../sub/thing.php` (the absolute path with its leading slash
        // dropped, because `str_replace($repositoryRoot, '', $real)` strips nothing from a root that
        // contains `..`), `isCurrent()` then looked for the file at `<root>/tmp/norm-.../sub/thing.php` --
        // a path that cannot exist -- and answered FALSE for a file that had just been indexed, while
        // `stats()` reported `stale 1` on an index that was correct. realpath() resolves the `..`;
        // the fallback keeps the old behaviour for a root that does not exist (nothing to index).
        $candidate = $repositoryRoot !== null ? $repositoryRoot : ($root . '/../../..');
        $canonical = realpath($candidate);
        $this->repositoryRoot = rtrim($canonical !== false ? $canonical : $candidate, '/');
        $this->indexPath = rtrim($root, '/') . '/index.json';
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException("Unable to create retrieval index root: {$root}");
        }
    }

    private readonly string $repositoryRoot;

    public function root(): string
    {
        return $this->root;
    }

    public function repositoryRoot(): string
    {
        return $this->repositoryRoot;
    }

    /**
     * Is this path material from a retired harness -- readable for its lessons, but not current instruction?
     *
     * WHY THIS EXISTS, MEASURED 2026-09-19. A brief built from this index for the Star Swarm work came
     * back with `tools/harpp2/projects/star-swarm-galaga.json` TIED FOR FIRST with the live game code and
     * `tools/harpp2/objectives/star-swarm-galaga-p12.md` third: 110 of 1228 indexed documents were the
     * harness that had been retired the same day, and a lane briefed from them would have implemented
     * last week's harness. The staleness gate could not see it and never will: `isCurrent()` compares mtime
     * and hash, and a retired file never changes, so it is PERMANENTLY current. That gate measures
     * CHANGED; this one measures STILL TRUE, and no amount of re-indexing turns one into the other.
     *
     * ENFORCED IN ONE PLACE. The alternative that was reached for first is a
     * `str_contains($path, 'harpp2')` at each call site. That reads as a coincidence rather than a policy,
     * cannot be argued with by a reader, and answers TRUE for a path that merely has the word in its name --
     * `tools/harpp2.md`, or a file that documents the harness. A prefix list says what is meant: the retired
     * harness's own material.
     *
     * NOT PARSED FROM tools/RETIRED.md, deliberately. That file is the single authoritative human record,
     * while this constant mirrors its four paths as executable policy. Parsing a Markdown table into a
     * runtime predicate would make a prose-format edit change retrieval behaviour invisibly. Keeping the
     * small mirror explicit makes drift reviewable, and the self-test below exercises every listed path.
     *
     * Evaluated at QUERY time, not stored at index time, and that is the point. A `retired: true` flag in
     * index.json is another attribute that never changes -- the exact failure this method exists to repair.
     * Change the policy and every stored flag would contradict it while still looking authoritative. The
     * documents stay indexed because they must stay countable and reachable; they are only kept out of a
     * brief unless a caller asks for them.
     */
    public static function isRetired(string $path): bool
    {
        $normalised = ltrim(str_replace('\\', '/', $path), '/');
        foreach (self::RETIRED_PREFIXES as $prefix) {
            // A directory prefix (trailing slash) retires everything under it; a file prefix retires
            // exactly that file. The second case is EXACT on purpose: `str_starts_with` would also retire
            // `tools/ai-run.php.bak`, which is a different artifact, and this list is a policy a reader
            // has to be able to predict.
            if (str_ends_with($prefix, '/')) {
                if (str_starts_with($normalised, $prefix)) {
                    return true;
                }
                continue;
            }
            if ($normalised === $prefix) {
                return true;
            }
        }

        return false;
    }

    // ── Indexing ────────────────────────────────────────────────────────────────────────────────

    /**
     * Index the given files and directories, skipping any document whose content has not changed.
     *
     * Incremental by content hash, and DIRECTIONAL: a file that vanished from the walk is removed, so
     * the index cannot answer with a path that no longer exists.
     *
     * @param list<string> $paths absolute or root-relative files and directories
     * @return array{indexed:int, unchanged:int, removed:int, skipped:int}
     */
    public function index(array $paths, bool $force = false): array
    {
        $state = $this->load();
        $documents = $state['documents'];
        $seen = [];
        $indexed = 0;
        $unchanged = 0;
        $skipped = 0;

        $files = $this->expand($paths);
        foreach ($files as $file) {
            $relative = $this->relative($file);
            $seen[$relative] = true;

            // Stat before reading. As a repository accumulates, reading and hashing every file on every
            // call is the cost that grows, and it grows with the part that did not change. The known
            // race -- a write inside one second with an unchanged size -- is what --force is for.
            $stat = @stat($file);
            if ($stat === false) {
                $skipped++;
                continue;
            }
            $entry = $documents[$relative] ?? null;
            if (!$force && $entry !== null
                && $entry['mtime'] === $stat['mtime']
                && $entry['size'] === $stat['size']) {
                $unchanged++;
                continue;
            }

            $contents = @file_get_contents($file);
            if ($contents === false) {
                $skipped++;
                continue;
            }

            $documents[$relative] = [
                'hash' => hash('sha256', $contents),
                'mtime' => (int) $stat['mtime'],
                'size' => (int) $stat['size'],
                'lines' => substr_count($contents, "\n") + 1,
                'terms' => $this->terms($contents, $relative),
                // Usage survives a reindex: a file that has served tasks stays useful when its content
                // changes slightly, and losing that on every edit would make the signal worthless. The
                // timestamp comes with it, because the credit decays and an undated credit cannot.
                'uses' => (int) ($entry['uses'] ?? 0),
                'last_used_at' => (int) ($entry['last_used_at'] ?? 0),
                'indexed_at' => gmdate(DATE_ATOM),
            ];
            $indexed++;
        }

        // PRUNE WHAT THIS CALL NO LONGER CONSIDERS CONTENT. The walk above skips vendored trees,
        // runtime directories and -- measured 2026-09-22 -- everything the repository itself declares
        // non-content in .gitignore. A previously indexed document that falls under a scope this call
        // walked and is absent from $seen for a PROVABLE reason (its file is gone, or it is no longer an
        // indexable/committed path) must be removed; repairing the walk alone would leave the credential
        // in every existing index until someone happened to `forget` it. A document that was merely
        // skipped by a transient stat/read failure is KEPT: it is still indexable, and dropping a good
        // document on one bad read is the opposite failure.
        //
        // The scope bound is deliberate: a scoped index() call must not delete the rest of the
        // repository. Only documents under the paths this call was given are candidates.
        $removed = 0;
        $scope = $this->indexScope($paths);
        if ($scope !== []) {
            $orphans = [];
            foreach (array_keys($documents) as $document) {
                if (isset($seen[$document]) || !$this->withinScope($document, $scope)) {
                    continue;
                }
                $absolute = $this->absolute($document);
                if (!is_file($absolute) || !$this->indexable($absolute)) {
                    unset($documents[$document]);
                    $removed++;
                    continue;
                }
                // It still exists and its extension/denylist status is fine, but the walk did not
                // hand it back -- the remaining provable reason is that .gitignore now excludes it.
                $orphans[] = $document;
            }
            foreach ($this->gitIgnored(array_map(fn (string $document): string => $this->absolute($document), $orphans)) as $relative => $_) {
                if (isset($documents[$relative])) {
                    unset($documents[$relative]);
                    $removed++;
                }
            }
        }

        $this->store(['version' => self::VERSION, 'updated_at' => gmdate(DATE_ATOM), 'documents' => $documents]);

        return ['indexed' => $indexed, 'unchanged' => $unchanged, 'removed' => $removed, 'skipped' => $skipped];
    }

    /** Is this document's indexed content still what is on disk? */
    public function isCurrent(string $path): bool
    {
        $relative = $this->relative($this->absolute($path));
        $entry = $this->load()['documents'][$relative] ?? null;
        if ($entry === null) {
            return false;
        }
        $contents = @file_get_contents($this->absolute($relative));

        return $contents !== false && hash('sha256', $contents) === $entry['hash'];
    }

    /**
     * Drop documents from the index.
     *
     * @param list<string> $paths files or directory prefixes
     * @return int the number removed
     */
    public function forget(array $paths): int
    {
        $state = $this->load();
        $prefixes = array_map(fn (string $path): string => rtrim($this->relative($this->absolute($path))), $paths);
        $removed = 0;
        foreach (array_keys($state['documents']) as $document) {
            foreach ($prefixes as $prefix) {
                if ($document === $prefix || str_starts_with($document, $prefix . '/')) {
                    unset($state['documents'][$document]);
                    $removed++;
                    break;
                }
            }
        }
        $state['updated_at'] = gmdate(DATE_ATOM);
        $this->store($state);

        return $removed;
    }

    // ── Searching ───────────────────────────────────────────────────────────────────────────────

    /**
     * Rank indexed documents against a query.
     *
     * THE RANKING RULE, stated in full, because a rule that cannot be written down is not a rule:
     *
     *   1. TERM WEIGHT. A term's weight is its rarity in the corpus (idf): `cratered` discriminates,
     *      `render` does not. Measured 2026-09-19 without it: a browser-game objective ranked
     *      `kernel/App.php` and `src/helpers/module-manager.php` in the top eight on the strength of
     *      "adding", "stage", "path", "table" and "render" -- terms that say nothing about the question.
     *   2. PATH BEATS BODY. A term in the path scores PATH_TERM_BONUS, a term in the body at most
     *      BODY_TERM_CAP: `star-swarm.js` is about the swarm in a way a file mentioning it is not.
     *   3. LENGTH NORMALISATION. Summed term counts grow with document size, so the score is divided by
     *      `1 + log(lines / average_lines)`, with a floor of 1 so a short document is not inflated. A
     *      file longer than the corpus average must be more, not merely longer, to outrank a focused one.
     *      Measured 2026-09-20: without it a 1773-line review brief that repeated `error` three extra
     *      times outranked the 140-line note the query was about, and drove the file that defines the
     *      index out of its own rank.
     *   4. BODY FREQUENCY SATURATES at BODY_TERM_CAP, so repeating one word cannot outrank covering
     *      the query, and the total is scaled by COVERAGE -- the share of the query's terms the document
     *      matched. Measured 2026-09-19 without it: a 11250-line handler that happened to contain six of
     *      the query's common words ranked above the 177-line file that contains the subject.
     *   5. USE BOOST, bounded and decaying -- see useBoost() for the bound and why it must decay.
     *   6. SPREAD. At most SPREAD_QUOTA hits per top-level area, with the displaced hits backfilled in
     *      score order: a lane handed eight near-copies of one directory has been given one answer.
     *      The rule can only reshuffle what the scores already ranked, never invent a hit.
     *
     * A query term that appears nowhere -- including only in retired material, which is not searchable by
     * default -- does not silently vanish: it is reported in `missing`, so a caller can tell "nothing
     * matched" from "the index is empty".
     *
     * RETIRED MATERIAL IS NOT IN THE CANDIDATE SET unless `$includeRetired` is set. The ranking rule
     * orders the documents a lane should read, and material from a harness that was retired is not one of
     * them -- see isRetired() for the measurement. Filtering BEFORE df/idf also keeps rarity honest: three
     * copies of a retired objective must not make a live term look common.
     *
     * @param list<string> $scope          optional path prefixes the result must fall under
     * @param bool         $includeRetired return retired material too. Default FALSE: the index keeps it
     *                                     for deliberate reference, it does not brief lanes with it
     * @return array{query:string, terms:list<string>, hits:list<Hit>, indexed:int, retired:int, missing:list<string>, confidence:string}
     */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT, array $scope = [], bool $includeRetired = false): array
    {
        $state = $this->load();
        $documents = $state['documents'];
        // strval is load-bearing, not cosmetic. PHP coerces numeric-string array keys to integers, so
        // array_keys() over a term map hands back the INT 2 for the token "2" -- and any query
        // containing a number then crashed the whole search in str_contains(). Measured 2026-09-19 on
        // the first real task: "the carrier drop reaches stage 2" took retrieval down, and with it
        // every delegated run, because the harness could not build a brief. Normalised once here so
        // every consumer downstream is safe rather than each one casting defensively.
        $terms = array_map('strval', array_keys($this->terms($query, '')));
        $indexed = count($documents);

        // Retired material is HELD BACK, not deleted: it stays indexed, stays countable in stats(), and
        // stays retrievable when a caller asks for it. See isRetired() for the measurement that made this
        // necessary, and for why the rule is evaluated here rather than stored per document in index.json.
        $candidates = [];
        $retired = 0;
        foreach ($documents as $path => $entry) {
            if (self::isRetired($path)) {
                $retired++;
                if (!$includeRetired) {
                    continue;
                }
            }
            $candidates[$path] = $entry;
        }
        $searchable = count($candidates);

        // Document frequency, for the query's terms only: one pass over the term maps, no extra reads.
        // Over the CANDIDATES, so a retired document cannot make a live term look common.
        $df = array_fill_keys($terms, 0);
        $totalLines = 0;
        foreach ($candidates as $entry) {
            $totalLines += (int) $entry['lines'];
            foreach ($terms as $term) {
                if (isset($entry['terms'][$term])) {
                    $df[$term]++;
                }
            }
        }
        // Average length of the searchable corpus, the pivot for the length normalisation below.
        $averageLines = $searchable > 0 ? max(1.0, $totalLines / $searchable) : 1.0;

        $now = time();
        $hits = [];
        $missing = $terms;
        foreach ($candidates as $path => $entry) {
            if ($scope !== [] && !$this->underScope($path, $scope)) {
                continue;
            }
            $score = 0.0;
            $matched = [];
            foreach ($terms as $term) {
                $inPath = str_contains(strtolower($path), $term);
                $inBody = (int) ($entry['terms'][$term] ?? 0);
                if (!$inPath && $inBody === 0) {
                    continue;
                }
                $matched[] = $term;
                $score += (($inPath ? self::PATH_TERM_BONUS : 0) + min(self::BODY_TERM_CAP, $inBody))
                    * self::idf($searchable, $df[$term]);
                $missing = array_values(array_diff($missing, [$term]));
            }
            // LENGTH NORMALISATION. Summed term counts grow with document size, so without a length
            // term a 1773-line review brief that merely repeats a query's common words outranks a
            // 140-line note that is about the subject. A document longer than the corpus average is
            // damped; a shorter one is left alone rather than inflated, so a one-line stub cannot win on
            // length alone. Measured 2026-09-20: with the flat rule, `docs/testing/harness-lessons.md`
            // (140 lines) lost to `docs/reviews/harness-independent-evaluation-brief.md` (1773 lines) on
            // three extra occurrences of `error`, and `RetrievalIndex.php` lost its own vocabulary to
            // files that repeated it more. See the ranking rule above.
            $lengthFactor = max(1.0, 1.0 + log(max(1.0, (int) $entry['lines']) / $averageLines));
            $score /= $lengthFactor;
            if ($score > 0) {
                // Coverage: a document that matches five of eleven query terms is a weaker answer than
                // one that matches eleven, and without this a long file wins on raw repetition of the
                // common ones. Scaling by the share of terms matched makes breadth of match part of the
                // rank rather than only the weight of each individual term.
                $score *= count($matched) / max(1, count($terms));
                $uses = (int) $entry['uses'];
                $hits[] = [
                    'path' => $path,
                    'score' => (int) round($score) + self::useBoost($uses, (int) $entry['last_used_at'], $now),
                    'lines' => (int) $entry['lines'],
                    'matched' => $matched,
                    'uses' => $uses,
                ];
            }
        }

        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['path'], $b['path']));

        $sliced = $this->spread($hits, $limit);
        $best = (int) ($sliced[0]['score'] ?? 0);

        // The decision point for a hybrid retriever. LOW is the only honest signal that local lexical
        // search has failed this question -- either nothing searchable is indexed, the query has no
        // searchable words, nothing matched at all, or most of the query's words are absent from the
        // repository. A caller may then reach for an online or embedding-backed retriever; it should not do
        // so when this says `good`, because code is identifier-shaped and exact names beat semantic
        // proximity.
        //
        // The test is over the SEARCHABLE count, not the document count: an index whose only remaining
        // documents are retired material is empty of anything a lane may be briefed with, and calling that
        // `low` would invite a hybrid retriever to answer a question this index simply does not hold.
        $confidence = 'good';
        if ($searchable === 0) {
            $confidence = 'empty';
        } elseif ($terms === [] || $hits === [] || count($missing) > count($terms) / 2 || $best < 4) {
            $confidence = 'low';
        }

        return [
            'query' => $query,
            'terms' => $terms,
            'hits' => $sliced,
            'indexed' => $indexed,
            'retired' => $retired,
            'missing' => $missing,
            'confidence' => $confidence,
        ];
    }

    /**
     * Record that these documents were actually used.
     *
     * The one signal a pure lexical index cannot derive: which files turned out to matter. The harness
     * knows, because it sees what a lane changed and which context preceded the change, so it reports
     * back and the next search ranks that file higher. This is what makes the index get more effective
     * with use rather than only larger.
     *
     * `uses` grows without limit because it is a fact worth reporting; the RANKING credit is capped and
     * decays (useBoost), so a hundred uses buy exactly as much as six did, and this month's uses buy
     * more than last year's. Also stamps `last_used_at`, which is what makes the decay possible.
     *
     * @param list<string> $paths
     * @return int how many were known to the index
     */
    public function recordUse(array $paths): int
    {
        $state = $this->load();
        $count = 0;
        foreach ($paths as $path) {
            $relative = $this->relative($this->absolute($path));
            if (!isset($state['documents'][$relative])) {
                continue;
            }
            $state['documents'][$relative]['uses'] = (int) $state['documents'][$relative]['uses'] + 1;
            $state['documents'][$relative]['last_used_at'] = time();
            $count++;
        }
        if ($count > 0) {
            $state['updated_at'] = gmdate(DATE_ATOM);
            $this->store($state);
        }

        return $count;
    }

    /**
     * The ranking credit a document has earned by having served a completed run.
     *
     * WHY THIS IS BOUNDED AND WHY IT DECAYS. Outcome feedback that only ever adds is a ranker that
     * recommends what it already recommended: every served task makes the same files look better, so
     * the context set narrows towards whatever it started with and nothing new can ever enter it. That
     * is worse than no feedback -- it is a self-reinforcing loop wearing the word "learning". So:
     *
     *   - the credit is CAPPED at USE_BOOST_MAX (6), below one path-term match, so a favourite cannot
     *     outrank a clearly better lexical match however many tasks it has served;
     *   - it HALVES every USE_HALF_LIFE_DAYS (14) of not being used, and a single use is therefore worth
     *     NOTHING after two half-lives (28 days), so a file that served tasks months ago must earn its
     *     place again. Measured 2026-09-19: the boost is the smallest term in the score, and that is
     *     deliberate -- it breaks ties and nudges, it does not decide;
     *   - a document with no timestamp (indexed before this existed) gets NO credit rather than
     *     permanent credit, because an undated use cannot be aged and "forever" is the one answer that
     *     reproduces the failure above.
     *
     * ROUNDED, not floored: a fresh use has an age of one second by the time anything reads it, so
     * floor() made a just-recorded use worth zero -- the credit would have disappeared between the run
     * that earned it and the search that should have used it. Rounding keeps credit 1 at 1 until the
     * age is worth half a unit.
     *
     * Public and pure so the bound and the decay can be asserted directly, without waiting 14 days.
     */
    public static function useBoost(int $uses, int $lastUsedAt, ?int $now = null): int
    {
        $credit = min(self::USE_BOOST_MAX, max(0, $uses));
        if ($credit === 0) {
            return 0;
        }
        $age = max(0, ($now ?? time()) - $lastUsedAt);

        return (int) round($credit * (0.5 ** ($age / (self::USE_HALF_LIFE_DAYS * 86400))));
    }

    /**
     * The indexed documents that no longer describe what is on disk, by path.
     *
     * `stats()` reported a bare `stale` count and nothing consumed it. A count cannot be acted on;
     * these paths can, in both of the ways staleness matters:
     *
     *   - RE-INDEX exactly them -- `index($index->stalePaths())` re-reads those documents and no others,
     *     which is the cheap repair for a repository where one file changed;
     *   - REFUSE to serve them -- a caller that must not brief a lane from a description of code that
     *     has changed can check this list (or `isCurrent()` per path) and stop.
     *
     * A document whose file is gone is in this list too: that is the same fact, one step further on.
     * Paths are repository-relative, exactly as `search()` returns them.
     *
     * @return list<string>
     */
    public function stalePaths(): array
    {
        $stale = [];
        foreach ($this->load()['documents'] as $path => $entry) {
            $contents = @file_get_contents($this->absolute($path));
            if ($contents === false || hash('sha256', $contents) !== $entry['hash']) {
                $stale[] = $path;
            }
        }

        return $stale;
    }

    /**
     * What is in the index, in numbers a human can act on.
     *
     * `stale` was the only actionable figure and it was a count; `stale_paths` is the list to re-index.
     * `used_paths` says which files outcome feedback is actually ranking -- if it is one file used two
     * hundred times, the feedback is a rut and this shows it; if it is empty, the loop is not wired to
     * anything and no amount of indexing will make retrieval learn.
     *
     * `retired` is here so an exclusion is visible rather than silent: a caller that cannot see how many
     * documents are being held back cannot tell a thin corpus from a filtered one. The documents are still
     * indexed and still counted in `documents` -- they are simply not briefed unless asked for.
     *
     * @return array{documents:int, lines:int, bytes:int, updated_at:?string, stale:int, stale_paths:list<string>, retired:int, used:int, used_paths:list<array{path:string, uses:int, boost:int, last_used_at:?string}>, root:string}
     */
    public function stats(): array
    {
        $state = $this->load();
        $lines = 0;
        $used = 0;
        $retired = 0;
        $now = time();
        $usedPaths = [];
        foreach ($state['documents'] as $path => $entry) {
            $lines += (int) $entry['lines'];
            if (self::isRetired($path)) {
                $retired++;
            }
            $uses = (int) $entry['uses'];
            $used += $uses;
            if ($uses === 0) {
                continue;
            }
            $lastUsedAt = (int) $entry['last_used_at'];
            $usedPaths[] = [
                'path' => $path,
                'uses' => $uses,
                'boost' => self::useBoost($uses, $lastUsedAt, $now),
                'last_used_at' => $lastUsedAt > 0 ? gmdate(DATE_ATOM, $lastUsedAt) : null,
            ];
        }
        usort(
            $usedPaths,
            static fn (array $a, array $b): int => $b['boost'] <=> $a['boost']
                ?: $b['uses'] <=> $a['uses']
                ?: strcmp($a['path'], $b['path'])
        );
        $stale = $this->stalePaths();

        return [
            'documents' => count($state['documents']),
            'lines' => $lines,
            'bytes' => (int) (@filesize($this->indexPath) ?: 0),
            'updated_at' => $state['updated_at'] ?? null,
            'stale' => count($stale),
            'stale_paths' => $stale,
            'retired' => $retired,
            'used' => $used,
            'used_paths' => array_slice($usedPaths, 0, 10),
            'root' => $this->root,
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────────

    /**
     * A term's weight: how much rarer it is in the corpus than in general (smoothed idf).
     *
     * 1.0 for a term that occurs in every document, rising as it becomes rare. Never below 1, so a
     * common term still counts for something and a query of common words still returns something --
     * it just cannot outrank a query term that actually discriminates.
     */
    private static function idf(int $documents, int $documentFrequency): float
    {
        return 1.0 + log(($documents + 1) / ($documentFrequency + 1));
    }

    /**
     * The area a path belongs to: its top-level directory, the unit the spread rule spreads over.
     *
     * Top-level, not immediate parent: `kernel/Workbench/Retrieval/a.php` and `kernel/App.php` are one
     * area, because eight files of kernel are still one answer to a lane.
     */
    private static function area(string $path): string
    {
        $slash = strpos($path, '/');

        return $slash === false ? $path : substr($path, 0, $slash);
    }

    /**
     * Select a ranked set with a per-area quota, then backfill the displaced hits in score order.
     *
     * The two passes are what make this a spread rather than a filter: pass one takes the best hits
     * while no area exceeds SPREAD_QUOTA, and pass two fills any remaining slots with the hits that
     * were deferred, still in score order. The result is exactly `$limit` hits, in the order a caller
     * should read them, and never a different set of documents than the scores chose.
     *
     * @param list<Hit> $hits score-ordered
     * @return list<Hit>
     */
    private function spread(array $hits, int $limit): array
    {
        $limit = max(0, $limit);
        if ($limit === 0 || count($hits) <= $limit) {
            return array_slice($hits, 0, $limit);
        }

        $taken = [];
        $deferred = [];
        $quota = [];
        foreach ($hits as $hit) {
            $area = self::area($hit['path']);
            if (($quota[$area] ?? 0) >= self::SPREAD_QUOTA) {
                $deferred[] = $hit;
                continue;
            }
            $quota[$area] = ($quota[$area] ?? 0) + 1;
            $taken[] = $hit;
            if (count($taken) === $limit) {
                return $taken;
            }
        }
        foreach ($deferred as $hit) {
            $taken[] = $hit;
            if (count($taken) === $limit) {
                break;
            }
        }

        return $taken;
    }

    /**
     * Terms of a document: words of four or more characters, with stopwords removed.
     *
     * A term appearing only once is kept: in source code, a distinctive identifier used once is often
     * exactly the signal a searcher wants, and frequency filtering would delete the finding.
     *
     * @return array<string,int>
     */
    private function terms(string $text, string $path): array
    {
        $terms = [];
        foreach (preg_split('/[^a-z0-9_]+/i', strtolower($path . ' ' . $text)) ?: [] as $word) {
            if (strlen($word) < 4 || isset(self::STOPWORDS[$word])) {
                continue;
            }
            $terms[$word] = ($terms[$word] ?? 0) + 1;
        }

        return $terms;
    }

    /**
     * Resolve paths to indexable files.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function expand(array $paths): array
    {
        // A WALK and an EXPLICIT PATH are not the same request. A directory is a scope the caller is
        // delegating to us, so the repository's own declaration of non-content (.gitignore) applies to
        // it. A path named explicitly is the caller's own declaration: the chair harness indexes a
        // scratch file under git-ignored storage/cache/ exactly that way, and its staleness controls
        // must keep working (tools/chair.php:3443-3445). Measured round 1: applying .gitignore to the
        // explicit path made a just-indexed file read as stale, and two chair controls failed.
        $explicit = [];
        $walked = [];
        foreach ($paths as $path) {
            $absolute = $this->absolute($path);
            if (is_file($absolute)) {
                if ($this->indexable($absolute)) {
                    $explicit[] = $absolute;
                }
                continue;
            }
            if (!is_dir($absolute)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $this->indexable($file->getPathname())) {
                    $walked[] = $file->getPathname();
                }
            }
        }
        // Honour the repository's own declaration of what is not content for WALKED files only.
        // `git check-ignore` answers for a whole batch, so the walk pays one process, not one per file.
        // See gitIgnored().
        if ($walked !== []) {
            $ignored = $this->gitIgnored($walked);
            if ($ignored !== []) {
                $walked = array_values(array_filter(
                    $walked,
                    fn (string $file): bool => !isset($ignored[$this->relative($file)])
                ));
            }
        }
        $files = array_values(array_unique(array_merge($explicit, $walked)));
        sort($files);

        return $files;
    }

    private function indexable(string $file): bool
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, self::INDEXABLE, true)) {
            return false;
        }
        // Vendored and generated trees describe other people's code, not this repository's. Indexing
        // them buries every real hit under framework noise. This denylist is the FALLBACK rule; it
        // cannot know that a runtime credentials directory exists, so the primary rule is .gitignore,
        // applied in expand() and in the prune step of index().
        foreach (['/vendor/', '/node_modules/', '/.git/', '/storage/private/', '/test-results/'] as $skip) {
            if (str_contains(str_replace('\\', '/', $file), $skip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does this checkout carry the ignore rules the walk should honour?
     *
     * The index is a cache of the repository's CONTENT, and .gitignore is the repository's own
     * declaration of what is not content: vendored trees, runtime state and credentials. Measured
     * 2026-09-22: tests/browser/.auth/tenant.json, a Playwright storageState holding a live session
     * token, was indexed as 28 terms with a 378-character term because indexable() knew only five
     * directory names. Honouring the declaration fixes the class of path, not the one path.
     *
     * Falls back to the name denylist when Git is unavailable (a deployed tree without .git), so the
     * index still runs, just less informed.
     */
    private function gitAvailable(): bool
    {
        if ($this->gitAvailable !== null) {
            return $this->gitAvailable;
        }
        if (!function_exists('proc_open')) {
            return $this->gitAvailable = false;
        }
        $dot = $this->repositoryRoot . '/.git';

        return $this->gitAvailable = (is_dir($dot) || is_file($dot));
    }

    /**
     * The repository-relative paths among $files that .gitignore declares are not content.
     *
     * Batch, not per file: the walk hands over hundreds of paths and one `git check-ignore --stdin`
     * answers all of them. Fail-open on any error -- an index that refuses to run because Git answered
     * badly is worse than one that momentarily falls back to the name denylist, and the denylist still
     * keeps vendor/ and storage/private/ out.
     *
     * @param list<string> $files absolute or root-relative paths
     * @return array<string,true> repository-relative paths that are ignored
     */
    private function gitIgnored(array $files): array
    {
        if ($files === [] || !$this->gitAvailable()) {
            return [];
        }
        $relative = [];
        foreach ($files as $file) {
            $path = $this->relative($this->absolute($file));
            if ($path !== '') {
                $relative[$path] = true;
            }
        }
        if ($relative === []) {
            return [];
        }
        // -z throughout: NUL-separated, so a path with a newline cannot smuggle a second one.
        $input = implode("\0", array_keys($relative)) . "\0";
        $process = @proc_open(
            ['git', '-C', $this->repositoryRoot, '-c', 'core.quotepath=false', 'check-ignore', '--stdin', '-z'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            return [];
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        // 0 means at least one path was ignored; 1 means none; anything else is Git refusing to answer.
        if ($status !== 0) {
            return [];
        }
        $ignored = [];
        foreach (explode("\0", $stdout) as $path) {
            if ($path !== '') {
                $ignored[$path] = true;
            }
        }

        return $ignored;
    }

    /**
     * The repository-relative prefixes this index() call walks.
     *
     * Used only to bound pruning: a scoped call may remove documents under the paths it was given, and
     * must not touch the rest of the repository. A file contributes its exact path, a directory its
     * prefix, so `index tests` can prune an old tests document while `index docs` cannot.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function indexScope(array $paths): array
    {
        $scope = [];
        foreach ($paths as $path) {
            $relative = $this->relative($this->absolute($path));
            if ($relative !== '') {
                $scope[$relative] = true;
            }
        }

        return array_keys($scope);
    }

    /**
     * Is this document inside one of the walked prefixes -- exactly, or under it?
     *
     * @param list<string> $prefixes
     */
    private function withinScope(string $document, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($document === $prefix || str_starts_with($document, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $scope */
    private function underScope(string $document, array $scope): bool
    {
        foreach ($scope as $entry) {
            $prefix = trim((string) $entry, '/');
            $prefix = rtrim($prefix, '*');
            if ($prefix === '' || str_starts_with($document, rtrim($prefix, '/')) || str_starts_with($prefix, $document)) {
                return true;
            }
            if (str_contains($prefix, '*')) {
                $pattern = '#^' . str_replace('\*', '.*', preg_quote($prefix, '#')) . '#';
                if (preg_match($pattern, $document) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->repositoryRoot . '/' . ltrim($path, '/');
    }

    private function relative(string $file): string
    {
        $real = realpath($file) ?: $file;

        return ltrim(str_replace('\\', '/', str_replace($this->repositoryRoot, '', $real)), '/');
    }

    /** @return array{version:int, updated_at?:string, documents:array<string,Entry>} */
    private function load(): array
    {
        if (!is_file($this->indexPath)) {
            return ['version' => self::VERSION, 'documents' => []];
        }
        $decoded = json_decode((string) @file_get_contents($this->indexPath), true);
        if (!is_array($decoded) || !isset($decoded['documents']) || !is_array($decoded['documents'])) {
            // A corrupt index is a rebuild, not a crash: the data is derivable from the filesystem.
            return ['version' => self::VERSION, 'documents' => []];
        }

        // The index on disk outlives the code that wrote it, so an entry read here can predate a field
        // this class declares. Measured 2026-09-20: documents indexed before the use-feedback ledger
        // existed arrived at search() without `last_used_at`, and reading it emitted one warning PER
        // MATCHING DOCUMENT -- 1576 warnings for one `recall` run. That is not cosmetic: the flood is
        // what filled the pipe and deadlocked the probe that ran it. Entries are restored to the shape
        // this class declares, so every consumer can read the key and none of them has to guess.
        $documents = [];
        foreach ($decoded['documents'] as $path => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            /** @var array<string,mixed> $entry */
            $entry += ['uses' => 0, 'last_used_at' => 0, 'lines' => 0, 'terms' => []];
            $entry['uses'] = (int) $entry['uses'];
            $entry['last_used_at'] = (int) $entry['last_used_at'];
            $entry['lines'] = (int) $entry['lines'];
            $documents[(string) $path] = $entry;
        }
        $decoded['documents'] = $documents;

        return $decoded;
    }

    /** @param array<string,mixed> $state */
    private function store(array $state): void
    {
        $temporary = $this->indexPath . '.tmp';
        // LOCK_EX plus a rename: a reader never sees a half-written index, which is what makes the
        // index trustworthy enough to replace reading the files.
        file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if (!rename($temporary, $this->indexPath)) {
            throw new \RuntimeException("Unable to write retrieval index: {$this->indexPath}");
        }
    }
}
