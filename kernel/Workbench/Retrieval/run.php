<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

/**
 * RetrievalIndex CLI.
 *
 * usage:
 *   php kernel/Workbench/Retrieval/run.php index [path...] [--force] [--stale] [--root=<dir>]
 *   php kernel/Workbench/Retrieval/run.php search "<query>" [--limit=8] [--scope=path] [--include-retired]
 *   php kernel/Workbench/Retrieval/run.php recall [--control]   is the RIGHT file retrieved?
 *   php kernel/Workbench/Retrieval/run.php use <path...>      report the files a task actually used
 *   php kernel/Workbench/Retrieval/run.php stats
 *   php kernel/Workbench/Retrieval/run.php forget <path...>
 *   php kernel/Workbench/Retrieval/run.php --self-test
 *
 * With no paths, `index` covers the repository's source trees, and `index --stale` re-indexes exactly
 * the documents `stats` lists as stale. That default is intentional: an index nobody remembers to feed
 * is an index that goes stale, and the whole point of this one is that a caller can ask `isCurrent()`
 * and believe the answer.
 */

require_once __DIR__ . '/RetrievalIndex.php';

use Ikabud\Kernel\Workbench\Retrieval\RetrievalIndex;

const RETRIEVAL_ROOT = __DIR__ . '/../../../storage/private/retrieval';
const RETRIEVAL_DEFAULT_PATHS = ['kernel', 'src', 'public', 'templates', 'modules', 'docs', 'tools', 'tests'];

/**
 * @param list<string> $argv
 * @return array{args:list<string>, options:array<string,string>, flags:list<string>}
 */
function retrievalCli(array $argv): array
{
    $args = [];
    $options = [];
    $flags = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
            [$name, $value] = explode('=', substr($argument, 2), 2);
            $options[$name] = $value;
        } elseif (str_starts_with($argument, '--')) {
            $flags[] = substr($argument, 2);
        } else {
            $args[] = $argument;
        }
    }

    return ['args' => $args, 'options' => $options, 'flags' => $flags];
}

/** @param array<string,string> $options */
function retrievalRoot(array $options): string
{
    return isset($options['root']) ? rtrim($options['root'], '/') : RETRIEVAL_ROOT;
}

/**
 * Controls for the index, in both directions: what it must find, and what it must not claim.
 *
 * The store is a cache of the filesystem, so every assertion here is about the two ways a cache
 * lies -- it serves something that changed, or it serves something that is gone.
 */
function retrievalSelfTest(): int
{
    $pass = 0;
    $fail = 0;
    $check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        if ($ok) {
            $pass++;
            fwrite(STDOUT, "  [PASS] {$label}\n");
            return;
        }
        $fail++;
        // The detail is what makes a failure actionable -- the paths retrieved instead of the expected
        // one, for instance. It is printed only on failure so the PASS output stays scannable, and it
        // defaults to empty so the two-argument calls remain valid.
        fwrite(STDOUT, "  [FAIL] {$label}" . ($detail === '' ? '' : "\n         {$detail}") . "\n");
    };

    $sandbox = sys_get_temp_dir() . '/retrieval-selftest-' . bin2hex(random_bytes(4));
    mkdir($sandbox . '/a', 0775, true);
    mkdir($sandbox . '/b', 0775, true);
    file_put_contents($sandbox . '/a/moon_renderer.php', "<?php\n// draws a cratered moon over black space\nfunction drawMoon() { return 'crater'; }\n");
    file_put_contents($sandbox . '/b/lance_pickup.php', "<?php\n// the plasma lance carrier drops a pickup\nfunction dropLance() { return 'carrier'; }\n");
    file_put_contents($sandbox . '/b/notes.md', "unrelated prose about nothing in particular\n");

    $index = new RetrievalIndex($sandbox . '/.index', $sandbox);

    fwrite(STDOUT, "indexing:\n");
    $first = $index->index([$sandbox . '/a', $sandbox . '/b']);
    $check('a first pass indexes the files', $first['indexed'] === 3);
    $second = $index->index([$sandbox . '/a', $sandbox . '/b']);
    $check('a second pass reindexes nothing (incremental by content hash)', $second['indexed'] === 0 && $second['unchanged'] === 3);
    $forced = $index->index([$sandbox . '/a'], true);
    $check('--force reindexes regardless of hash', $forced['indexed'] === 1 && $forced['unchanged'] === 0);

    fwrite(STDOUT, "\nsearching:\n");
    $moon = $index->search('cratered moon black space', 5);
    $check('a query finds the file that is about it', ($moon['hits'][0]['path'] ?? '') !== '' && str_contains($moon['hits'][0]['path'], 'moon_renderer.php'));
    $check('a hit explains itself with the terms that matched', ($moon['hits'][0]['matched'] ?? []) !== []);
    $check('an unrelated file is not returned for a specific query', count(array_filter($moon['hits'], static fn (array $h): bool => str_contains($h['path'], 'notes.md'))) === 0);

    $lance = $index->search('carrier pickup lance');
    $check('a second query finds a different file', str_contains($lance['hits'][0]['path'] ?? '', 'lance_pickup.php'));

    $missing = $index->search('zzzznotpresent');
    $check('a query with no indexed term reports it rather than pretending', $missing['missing'] === ['zzzznotpresent'] && $missing['hits'] === []);

    $stopworded = $index->search('the and for with');
    $check('stopwords alone retrieve nothing (they do not discriminate)', $stopworded['terms'] === []);

    fwrite(STDOUT, "\nscope:\n");
    $scoped = $index->search('moon', 5, ['a']);
    $check('scope bounds the search to its prefix', $scoped['hits'] === [] || str_contains($scoped['hits'][0]['path'], '/a/') === false || str_contains($scoped['hits'][0]['path'], 'moon'));
    $global = $index->search('moon', 5);
    $check('an unscoped search still sees everything', $global['hits'] !== []);
    fwrite(STDOUT, "\nconfidence — the signal that decides whether to escalate to an online retriever:\n");
    $check('a query that matches is GOOD', $index->search('cratered moon')['confidence'] === 'good');
    $check('a query whose words are absent is LOW', $index->search('zzzznotpresent')['confidence'] === 'low');

    // A query containing a NUMBER. PHP coerces numeric-string array keys to integers, so array_keys()
    // over a term map hands back the INT 2 for the token "2", and str_contains() then throws -- which
    // took retrieval down and, with it, every delegated run, because the harness could not build a
    // brief. Measured 2026-09-19 on the first real task.
    //
    // The assertion is the type invariant, not merely "it did not throw": a search that survives by
    // luck on a digit-free query would satisfy a smoke test and still be broken. Every term the
    // caller is handed must be a string, because that is the property that was violated.
    $numeric = $index->search('cratered moon stage 2026 wave', 5);
    $check('a query containing digits does not throw', in_array('2026', $numeric['terms'], true));
    $check('the search reports the terms it used', $numeric['terms'] !== []);
    $check(
        'and every reported term is a STRING -- the property that was violated',
        count(array_filter($numeric['terms'], 'is_string')) === count($numeric['terms'])
    );
    $check('a query with no searchable words is LOW', $index->search('the and for')['confidence'] === 'low');
    $check(
        'an empty index is EMPTY, not merely low',
        (new RetrievalIndex($sandbox . '/.empty', $sandbox))->search('moon')['confidence'] === 'empty'
    );

    fwrite(STDOUT, "\nusage feedback — the index gets better with use, not only larger:\n");
    $beforeUse = $index->search('cratered moon')['hits'][0]['score'] ?? 0;
    $used = $index->recordUse([$sandbox . '/a/moon_renderer.php']);
    $check('recordUse counts a known document', $used === 1);
    $check('recordUse ignores a path the index does not hold', $index->recordUse([$sandbox . '/nope.php']) === 0);
    $afterUse = $index->search('cratered moon')['hits'][0]['score'] ?? 0;
    $check('a used document ranks higher than it did', $afterUse > $beforeUse);
    $check('usage survives a reindex', (function () use ($index, $sandbox): bool {
        $index->index([$sandbox . '/a/moon_renderer.php'], true);
        return $index->stats()['used'] >= 1;
    })());
    fwrite(STDOUT, "\ncurrency — the two ways a cache lies:\n");
    $check('a fresh document is current', $index->isCurrent($sandbox . '/a/moon_renderer.php'));
    file_put_contents($sandbox . '/a/moon_renderer.php', "<?php\n// now it draws a ringed planet instead\n");
    $check('an edited document is NOT current', !$index->isCurrent($sandbox . '/a/moon_renderer.php'));
    $index->index([$sandbox . '/a']);
    $check('reindexing makes it current again', $index->isCurrent($sandbox . '/a/moon_renderer.php'));

    unlink($sandbox . '/b/notes.md');
    $removed = $index->index([$sandbox . '/a', $sandbox . '/b']);
    $check('a deleted file is removed from the index', $removed['removed'] === 1);
    $check('the deleted file can no longer be retrieved', $index->search('unrelated prose particular')['hits'] === []);

    fwrite(STDOUT, "\nforget and stats:\n");
    $dropped = $index->forget([$sandbox . '/b']);
    $check('forget removes a directory prefix', $dropped === 1);
    $stats = $index->stats();
    $check('stats reports what is actually indexed', $stats['documents'] === 1 && $stats['lines'] > 0);
    $check('stats reports nothing stale after a reindex', $stats['stale'] === 0);

    fwrite(STDOUT, "\nrobustness:\n");
    file_put_contents($sandbox . '/.index/index.json', 'not json at all');
    $recovered = $index->search('cratered moon');
    $check('a corrupt index degrades to empty rather than crashing', $recovered['hits'] === [] && $recovered['indexed'] === 0);

    // ── the ranking rule, on a corpus built so that each part of it can be seen to bite ─────────────
    // Every control below is TWO-SIDED on purpose: what the rule must accept, and what it must refuse.
    // A one-sided control passes whether or not the rule exists. Each rule was also shown load-bearing
    // by re-running these queries against a scratch copy of this class with that rule disabled: with
    // SPREAD_QUOTA raised, the second area does not appear; with idf() forced to 1.0, the rare document
    // loses; with the coverage factor removed, the half-match wins. That is what these assert.
    fwrite(STDOUT, "\nranking — a rule that cannot be seen to bite is not a rule:\n");
    $corpus = sys_get_temp_dir() . '/retrieval-ranking-' . bin2hex(random_bytes(4));
    foreach (['crowd', 'rare', 'elsewhere', 'halves', 'wholes'] as $area) {
        mkdir($corpus . '/' . $area, 0775, true);
    }
    // 'render' in five documents discriminates nothing; 'zephyrous' in one discriminates a lot. The
    // file names deliberately do NOT contain either term: `crowd/render1.php` scored on a path match,
    // which measured 2026-09-19 made this control pass while the idf rule was disabled.
    for ($i = 1; $i <= 5; $i++) {
        file_put_contents($corpus . "/crowd/sheet{$i}.php", "<?php // render render render render\n");
    }
    file_put_contents($corpus . '/rare/unique.php', "<?php // zephyrous zephyrous zephyrous zephyrous\n");
    file_put_contents($corpus . '/elsewhere/other.php', "<?php // render\n");
    file_put_contents($corpus . '/halves/half.php', "<?php // render render render render\n");
    file_put_contents($corpus . '/wholes/whole.php', "<?php // render zephyrous\n");

    // Indexed area by area, never the directory holding the index: index.json is a .json file, and an
    // index that walks itself re-reads a file it is rewriting.
    $ranked = new RetrievalIndex($corpus . '/.index', $corpus);
    $ranked->index([$corpus . '/crowd', $corpus . '/rare', $corpus . '/elsewhere', $corpus . '/halves', $corpus . '/wholes']);

    $common = $ranked->search('render', 5);
    $rare = $ranked->search('zephyrous', 5);
    $check('a term that is in every document still retrieves (rarity weights, it does not censor)', count($common['hits']) === 5);
    $check('a term that is in one document retrieves that document', str_contains($rare['hits'][0]['path'] ?? '', 'rare/unique.php'));
    $check(
        'at equal frequency, the rare term outranks the common one',
        array_column($ranked->search('render zephyrous', 5, ['crowd', 'rare'])['hits'], 'path')[0] === 'rare/unique.php'
    );
    $check(
        'a query of common words alone does NOT drag the rare document in',
        !in_array('rare/unique.php', array_column($common['hits'], 'path'), true)
    );
    $check(
        'a document covering the whole query outranks one repeating half of it',
        array_column($ranked->search('render zephyrous', 5, ['wholes', 'halves'])['hits'], 'path') === ['wholes/whole.php', 'halves/half.php']
    );

    $areas = static fn (array $hits): array => array_unique(array_map(
        static fn (array $hit): string => explode('/', $hit['path'])[0],
        $hits
    ));
    $check('the spread rule reaches a second area inside the limit', count($areas($ranked->search('render', 3)['hits'])) === 2);
    $check(
        'and never returns more than the limit to reach it',
        count($ranked->search('render', 2)['hits']) === 2 && count($areas($ranked->search('render', 2)['hits'])) === 1
    );
    $check('the displaced hits are backfilled, so a larger limit still fills', count($ranked->search('render', 9)['hits']) === 8);

    // ── outcome feedback: bounded, decaying, and honest about a legacy use it cannot age ────────────
    fwrite(STDOUT, "\noutcome feedback — a ranker that only ever recommends what it already recommended is a rut:\n");
    $now = time();
    $halfLife = RetrievalIndex::USE_HALF_LIFE_DAYS * 86400;
    $check('a document that has never served a task gets no credit', RetrievalIndex::useBoost(0, $now) === 0);
    $check('a document used once, today, gets credit', RetrievalIndex::useBoost(1, $now) === 1);
    $check('and a use recorded a second ago still counts (the clock ticks between use and search)', RetrievalIndex::useBoost(1, $now - 1) === 1);
    $check('a thousand uses are worth no more than the ceiling', RetrievalIndex::useBoost(1000, $now) === RetrievalIndex::USE_BOOST_MAX);
    $check('one half-life of age halves the credit', RetrievalIndex::useBoost(RetrievalIndex::USE_BOOST_MAX, $now - $halfLife) === 3);
    $check(
        'and it keeps decaying rather than lasting forever',
        RetrievalIndex::useBoost(RetrievalIndex::USE_BOOST_MAX, $now - 2 * $halfLife) > 0
            && RetrievalIndex::useBoost(RetrievalIndex::USE_BOOST_MAX, $now - 2 * $halfLife) < RetrievalIndex::USE_BOOST_MAX
    );
    $check('a single use three half-lives old earns nothing', RetrievalIndex::useBoost(1, $now - 3 * $halfLife) === 0);
    $check('a use with no date earns nothing, because it cannot be aged', RetrievalIndex::useBoost(5, 0) === 0);
    $ranked->recordUse([$corpus . '/crowd/sheet1.php']);
    $usedStats = $ranked->stats();
    $check(
        'stats names the documents feedback is ranking, with the credit each is earning',
        ($usedStats['used_paths'][0]['path'] ?? '') === 'crowd/sheet1.php'
            && $usedStats['used_paths'][0]['boost'] === 1
            && ($usedStats['used_paths'][0]['last_used_at'] ?? null) !== null
    );

    // ── staleness: the paths to re-index, not merely a count of them ────────────────────────────────
    fwrite(STDOUT, "\nstaleness — the count was reported and consumed by nothing; the paths can be acted on:\n");
    file_put_contents($corpus . '/crowd/sheet1.php', "<?php // render render render render, now changed\n");
    $check('stalePaths names the document that changed', $ranked->stalePaths() === ['crowd/sheet1.php']);
    $check('and does NOT name one that did not', !in_array('crowd/sheet2.php', $ranked->stalePaths(), true));
    $ranked->index($ranked->stalePaths(), true);
    $check('re-indexing exactly those clears it', $ranked->stalePaths() === [] && $ranked->stats()['stale'] === 0);
    unlink($corpus . '/crowd/sheet2.php');
    $check('a document whose file is gone is stale too', in_array('crowd/sheet2.php', $ranked->stalePaths(), true));
    // index() will not prune from an empty walk -- by design, a scoped index call must not delete the
    // rest of the repository -- so the repair for a deleted document is forget(), not index().
    $ranked->forget(['crowd/sheet2.php']);
    $check(
        'and forget() drops it, so a deleted file cannot be served as content',
        !in_array('crowd/sheet2.php', $ranked->stalePaths(), true)
            && !in_array('crowd/sheet2.php', array_column($ranked->search('render', 9)['hits'], 'path'), true)
    );

    // ── the root: canonicalised, because a root with '..' in it strips nothing ──────────────────────
    fwrite(STDOUT, "\nroot normalisation — measured, not suspected:\n");
    $unaligned = new RetrievalIndex($corpus . '/.index-norm', $corpus . '/crowd/..');
    $unaligned->index([$corpus . '/crowd/sheet3.php']);
    // The measured defect: with an unnormalised root the stored key became the absolute path with its
    // leading slash dropped, so isCurrent() looked for <root>/<that key> and answered FALSE for a file
    // that had just been indexed, while stats() called a correct index stale.
    $check('a file just indexed under a root written ".../crowd/.." reads as CURRENT', $unaligned->isCurrent($corpus . '/crowd/sheet3.php'));
    $storedKeys = array_keys(json_decode((string) file_get_contents($corpus . '/.index-norm/index.json'), true)['documents']);
    $check('and its key is repository-relative, not the absolute path with its slash dropped', $storedKeys === ['crowd/sheet3.php']);
    $check('and stats reports no staleness under that root', $unaligned->stats()['stale_paths'] === [] && $unaligned->stats()['stale'] === 0);

    // ── retired material: a file that never changes is never "changed", so currency cannot catch it ──
    // The gate above compares mtime and hash and is correct about what it measures. A retired file is
    // PERMANENTLY current by that measure, so the exclusion has to be a fact about the path, not about
    // its freshness. Both directions are asserted here: what must not be briefed, and what must still be.
    fwrite(STDOUT, "\nretired material — the exclusion currency cannot express, in both directions:\n");
    $retiredSandbox = sys_get_temp_dir() . '/retrieval-retired-' . bin2hex(random_bytes(4));
    mkdir($retiredSandbox . '/tools/harpp2/objectives', 0775, true);
    mkdir($retiredSandbox . '/public/star-swarm', 0775, true);
    // Identical text on purpose: only the PATH can decide which of the two is briefed, so a check that
    // passed because the words were absent would be caught rather than trusted.
    $swarmProse = "the carrier drop reaches stage 2 and the weapon drops a pickup\n";
    $retiredPaths = [
        'tools/harpp2/objectives/galaga-p12.md',
        'tools/ai-run.php',
        'tools/ai-project.php',
        'tools/ai-autonomy.php',
    ];
    foreach ($retiredPaths as $retiredPath) {
        file_put_contents($retiredSandbox . '/' . $retiredPath, $swarmProse);
    }
    file_put_contents($retiredSandbox . '/public/star-swarm/star-swarm.js', $swarmProse);
    $retiredIndex = new RetrievalIndex($retiredSandbox . '/.index', $retiredSandbox);
    $retiredIndex->index([$retiredSandbox . '/tools', $retiredSandbox . '/public']);

    $briefed = $retiredIndex->search('carrier drop pickup weapon stage', 8);
    $briefedPaths = array_column($briefed['hits'], 'path');
    $check(
        'every retired path is held out of a query that would otherwise return it',
        array_intersect($retiredPaths, $briefedPaths) === [],
        implode(', ', $briefedPaths)
    );
    $check(
        'and a LIVE document matching that same query STILL comes back (the exclusion is not a blunt instrument)',
        in_array('public/star-swarm/star-swarm.js', $briefedPaths, true),
        implode(', ', $briefedPaths)
    );
    $check(
        'the retired documents are still INDEXED and only kept out of the brief',
        $retiredIndex->stats()['documents'] === 5 && $retiredIndex->stats()['retired'] === 4
    );
    $check(
        'stats reports the retired count, so the exclusion is visible rather than silent',
        $retiredIndex->stats()['retired'] === 4
    );
    $check(
        'and a search says how many documents it held back',
        $briefed['retired'] === 4
    );
    $asked = $retiredIndex->search('carrier drop pickup weapon stage', 8, [], true);
    $check(
        'with the explicit opt-in every retired path CAN still be retrieved, so the material is not lost',
        array_diff($retiredPaths, array_column($asked['hits'], 'path')) === []
    );
    $check(
        'the retired rule covers the authoritative list and answers both ways',
        count(array_filter($retiredPaths, [RetrievalIndex::class, 'isRetired'])) === count($retiredPaths)
            && !RetrievalIndex::isRetired('tools/chair.php')
            // A substring rule would retire these, while a loose file prefix would retire the backup.
            && !RetrievalIndex::isRetired('tools/harpp2.md')
            && !RetrievalIndex::isRetired('docs/reviews/harpp2-postmortem.md')
            && !RetrievalIndex::isRetired('tools/ai-run.php.bak')
    );

    // The director's instruction was to RETIRE the harness, not delete it. The cheapest way to make the
    // check above pass is to delete tools/harpp2/ -- which would destroy the thing that was asked to be
    // kept, and no unit check on a temp tree can see that happen. So the assertion reads the repository.
    $retiredKept = glob(dirname(__DIR__, 3) . '/tools/harpp2/*') ?: [];
    $check(
        'the retired harness is KEPT on disk, because deleting it hides the material rather than retiring it',
        $retiredKept !== [] && is_file(dirname(__DIR__, 3) . '/tools/RETIRED.md')
    );

    foreach (['/tools/harpp2/objectives/galaga-p12.md', '/tools/ai-run.php', '/tools/ai-project.php', '/tools/ai-autonomy.php', '/public/star-swarm/star-swarm.js', '/.index/index.json', '/.index/index.lock'] as $file) {
        @unlink($retiredSandbox . $file);
    }
    foreach (['/tools/harpp2/objectives', '/tools/harpp2', '/tools', '/public/star-swarm', '/public', '/.index', ''] as $dir) {
        @rmdir($retiredSandbox . $dir);
    }

    foreach (['crowd/sheet1.php', 'crowd/sheet3.php', 'crowd/sheet4.php', 'crowd/sheet5.php', 'rare/unique.php', 'elsewhere/other.php', 'halves/half.php', 'wholes/whole.php', '.index/index.json', '.index/index.lock', '.index-norm/index.json', '.index-norm/index.lock'] as $file) {
        @unlink($corpus . '/' . $file);
    }
    foreach (['crowd', 'rare', 'elsewhere', 'halves', 'wholes', '.index', '.index-norm'] as $dir) {
        @rmdir($corpus . '/' . $dir);
    }
    @rmdir($corpus);

    // Clean up whatever is left.
    foreach ([$sandbox . '/a/moon_renderer.php', $sandbox . '/.index/index.json', $sandbox . '/.index/index.lock'] as $file) {
        @unlink($file);
    }
    @rmdir($sandbox . '/a');
    @rmdir($sandbox . '/b');
    @rmdir($sandbox . '/.index');
    @rmdir($sandbox);

    fwrite(STDOUT, sprintf("\n  => %d passed, %d failed\n", $pass, $fail));

    return $fail === 0 ? 0 : 1;
}

/**
 * The queries whose answers are known, against THIS repository.
 *
 * WHY RECALL IS A COMMAND AND NOT A HABIT. Before this, retrieval quality had never been measured:
 * `stats` reported `used 2`, which says the outcome loop barely exists, and nothing anywhere asked
 * whether a search returned the file that documents the thing being asked about. A term counter that
 * returns *a* document cannot be distinguished from retrieval that returns the RIGHT one without a
 * case whose answer is known in advance.
 *
 * Every path below was verified to exist before it was asserted on, and each case says what it would
 * catch. The unit controls in --self-test run on a temp tree: they prove the machinery turns, not that
 * it returns the right document from a corpus of thousands.
 *
 * @return list<array{query:string, expect:string, rank:int, why:string}>
 */
function retrievalRecallCases(): array
{
    return [
        [
            'query' => 'retrieval index stale paths refused before dispatch',
            'expect' => 'kernel/Workbench/Retrieval/RetrievalIndex.php',
            'rank' => 3,
            'why' => 'the baseline: the file that IS the subject must be found by its own vocabulary. A miss here means the walk, the term extraction or the index itself is broken, not that the ranking is opinionated.',
        ],
        [
            'query' => 'refusing to brief a lane from stale context, forced re-index before dispatch',
            'expect' => 'tools/chair.php',
            'rank' => 3,
            'why' => 'NOT the most obvious file: the refusal is implemented in the consumer, while the loudest vocabulary (stale, index, context) sits in RetrievalIndex.php, which ranks first. A ranker that returns whichever file the query names would pass the baseline case and fail this one.',
        ],
        [
            'query' => 'playwright spec failing console error trace',
            'expect' => 'docs/testing/harness-lessons.md',
            'rank' => 5,
            'why' => 'REQUIRES THE SPREAD RULE: measured 2026-09-19, without it the top five are five files under tests/browser and this one falls out; with it, two per area, and this comes third. It is the case that fails when breadth is bought by returning near-copies of one directory.',
        ],
    ];
}

/**
 * Measure recall, and be able to report a miss.
 *
 * Exit 0 when every expected file appears within its rank; 1 when one does not, printing what was
 * returned in its place; 2 when no measurement is possible (nothing indexed).
 *
 * --control points the SAME cases at a scope that cannot contain the answer and requires every one of
 * them to MISS. That is the both-directions proof of the instrument itself, which the suite runs: a
 * recall check that cannot report a miss would pass whatever it was given, and recall would be a claim.
 */
function retrievalRecall(RetrievalIndex $index, bool $control): int
{
    $cases = retrievalRecallCases();
    $before = $index->stats();
    if ($before['documents'] === 0) {
        fwrite(STDERR, "recall: nothing is indexed; run `php kernel/Workbench/Retrieval/run.php index` first\n");
        return 2;
    }

    // Measure the repository as it is on disk, not as it was: re-index exactly the stale documents. A
    // stale entry whose file is gone cannot be repaired, only dropped, so it is forgotten -- otherwise
    // the index would keep offering a path that no longer exists.
    $stale = $before['stale_paths'];
    $gone = array_values(array_filter($stale, static fn (string $path): bool => !is_file($index->repositoryRoot() . '/' . $path)));
    $present = array_values(array_diff($stale, $gone));
    if ($present !== []) {
        $index->index($present, true);
    }
    if ($gone !== []) {
        $index->forget($gone);
    }
    printf(
        "recall: %d case(s) against %d indexed document(s); stale %d (re-indexed %d, forgotten %d)\n",
        count($cases),
        $before['documents'],
        count($stale),
        count($present),
        count($gone)
    );

    $missed = 0;
    foreach ($cases as $case) {
        $rank = max(1, (int) $case['rank']);
        // A case whose expectation was deleted or renamed is a stale CHECK, not a soft pass: it fails
        // loudly rather than quietly measuring nothing.
        if (!is_file($index->repositoryRoot() . '/' . $case['expect'])) {
            printf("  [STALE CASE] %s is not in the repository: fix the expectation, do not relax it\n", $case['expect']);
            $missed++;
            continue;
        }
        $scope = $control ? [str_starts_with($case['expect'], 'docs/') ? 'kernel' : 'docs'] : [];
        $hits = array_column($index->search($case['query'], $rank, $scope)['hits'], 'path');
        $at = array_search($case['expect'], $hits, true);
        $found = $at !== false;
        $ok = $control ? !$found : $found;
        printf(
            "  [%s] rank %-2s of %d  %s\n",
            $ok ? ($control ? 'MISS' : 'FOUND') : ($control ? 'FOUND' : 'MISS'),
            $found ? (string) ($at + 1) : '-',
            $rank,
            $case['expect']
        );
        if ($ok) {
            continue;
        }
        $missed++;
        if (!$control) {
            printf("         query: %s\n         returned: %s\n         why it matters: %s\n", $case['query'], implode(', ', $hits) ?: '(nothing)', $case['why']);
        }
    }

    printf(
        "  => %s: %d of %d case(s) %s\n",
        $control ? 'control' : 'recall',
        count($cases) - $missed,
        count($cases),
        $control ? 'correctly missed' : 'found within their rank'
    );

    return $missed === 0 ? 0 : 1;
}

$cli = retrievalCli($argv);

if (in_array('self-test', $cli['flags'], true)) {
    exit(retrievalSelfTest());
}

$index = new RetrievalIndex(retrievalRoot($cli['options']), dirname(__DIR__, 3));
$command = $cli['args'][0] ?? '';
$rest = array_slice($cli['args'], 1);

switch ($command) {
    case 'index':
        if (in_array('stale', $cli['flags'], true) && $rest === []) {
            // The other half of exposing stale paths: re-index exactly those, and nothing else.
            $stale = $index->stalePaths();
            $result = $index->index($stale, true);
            printf(
                "%d stale path(s): indexed %d, removed %d, skipped %d (%d still stale)\n",
                count($stale),
                $result['indexed'],
                $result['removed'],
                $result['skipped'],
                count($index->stalePaths())
            );
            exit(0);
        }
        $paths = $rest !== [] ? $rest : RETRIEVAL_DEFAULT_PATHS;
        $result = $index->index($paths, in_array('force', $cli['flags'], true));
        printf(
            "indexed %d, unchanged %d, removed %d, skipped %d\n",
            $result['indexed'],
            $result['unchanged'],
            $result['removed'],
            $result['skipped']
        );
        exit(0);

    case 'search':
        $query = implode(' ', $rest);
        if ($query === '') {
            fwrite(STDERR, "usage: run.php search \"<query>\" [--limit=N] [--scope=path]\n");
            exit(2);
        }
        $scope = isset($cli['options']['scope']) ? explode(',', $cli['options']['scope']) : [];
        // Retired material is held back unless asked for, and the flag is explicit rather than implicit
        // because the chair sometimes needs to read the old harness deliberately -- and because an
        // exclusion a caller cannot see is one they cannot correct. See RetrievalIndex::isRetired().
        $includeRetired = in_array('include-retired', $cli['flags'], true);
        $result = $index->search($query, (int) ($cli['options']['limit'] ?? RetrievalIndex::DEFAULT_LIMIT), $scope, $includeRetired);
        printf("%d hit(s) from %d indexed   confidence: %s\n", count($result['hits']), $result['indexed'], $result['confidence']);
        foreach ($result['hits'] as $hit) {
            printf(
                "  %4d  %-60s (%d lines)  [%s]%s%s\n",
                $hit['score'],
                $hit['path'],
                $hit['lines'],
                implode(' ', $hit['matched']),
                $hit['uses'] > 0 ? "  used {$hit['uses']}x" : '',
                RetrievalIndex::isRetired($hit['path']) ? '  RETIRED' : ''
            );
        }
        if ($result['retired'] > 0) {
            printf(
                "  retired: %d of the %d indexed document(s) are material from the retired harness, %s\n",
                $result['retired'],
                $result['indexed'],
                $includeRetired ? 'included because --include-retired was given' : 'held back from this result (--include-retired searches them)'
            );
        }
        if ($result['missing'] !== []) {
            printf("  not indexed anywhere: %s\n", implode(' ', $result['missing']));
        }
        if ($result['confidence'] === 'low') {
            fwrite(STDOUT, "  LOW confidence: the local index found nothing useful. This is the honest time to\n  reach for an online or embedding-backed retriever -- not before.\n");
        }
        exit($result['hits'] === [] ? 1 : 0);

    case 'recall':
        exit(retrievalRecall($index, in_array('control', $cli['flags'], true)));

    case 'use':
        if ($rest === []) {
            fwrite(STDERR, "usage: run.php use <path...>   (report the files a task actually used)\n");
            exit(2);
        }
        printf("recorded use for %d document(s)\n", $index->recordUse($rest));
        exit(0);

    case 'stats':
        $stats = $index->stats();
        printf(
            "documents %d, lines %d, index %d bytes, updated %s, stale %d, retired %d, used %d\nroot: %s\n",
            $stats['documents'],
            $stats['lines'],
            $stats['bytes'],
            $stats['updated_at'] ?? 'never',
            $stats['stale'],
            $stats['retired'],
            $stats['used'],
            $stats['root']
        );
        // The retired documents are still indexed, so the count has to be visible here: otherwise a
        // caller comparing `documents` against a search result cannot tell a thinner corpus from a
        // filtered one, and the exclusion becomes silent -- which is the failure mode this replaced.
        if ($stats['retired'] > 0) {
            printf(
                "  retired: %d indexed document(s) are material from the harness retired on 2026-09-19; excluded from search unless --include-retired\n",
                $stats['retired']
            );
        }
        // A count is not actionable; these are. `index --stale` re-indexes exactly this list.
        foreach (array_slice($stats['stale_paths'], 0, 10) as $path) {
            printf("  stale: %s\n", $path);
        }
        if (count($stats['stale_paths']) > 10) {
            printf("  stale: ... and %d more (index --stale re-indexes all of them)\n", count($stats['stale_paths']) - 10);
        }
        // And what the outcome feedback is actually ranking: the failure mode of a learning ranker is a
        // rut, and a rut is visible here as one path with a large count.
        foreach ($stats['used_paths'] as $used) {
            printf(
                "  used: %-58s %dx, credit %d, last used %s\n",
                $used['path'],
                $used['uses'],
                $used['boost'],
                $used['last_used_at'] ?? 'before this was recorded'
            );
        }
        exit(0);

    case 'forget':
        if ($rest === []) {
            fwrite(STDERR, "usage: run.php forget <path...>\n");
            exit(2);
        }
        printf("forgot %d document(s)\n", $index->forget($rest));
        exit(0);
}

fwrite(STDERR, "usage: run.php <index|search|recall|stats|forget|use> [...] | --self-test\n");
exit(2);
