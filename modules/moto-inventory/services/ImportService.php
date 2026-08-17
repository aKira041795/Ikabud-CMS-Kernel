<?php

declare(strict_types=1);

/**
 * Moto Inventory — ImportService
 *
 * Server-side XLSX pricelist import and safe versioned data export/import.
 *
 * Files are untrusted: extension, ZIP signature, size, sheet/row/cell counts,
 * field lengths, numerics, and duplicates are all validated before anything
 * is persisted. Imports are STAGED for review and COMMITTED atomically.
 * The legacy arbitrary full-DB restore is replaced with a schema-versioned,
 * module-owned export/import that uses public business keys only — imported
 * IDs, tenant IDs, actors, audit rows, and arbitrary tables are refused.
 */
final class ImportService
{
    public const BACKUP_VERSION = '1';
    public const MAX_FILE_SIZE = 8 * 1024 * 1024; // 8 MB
    public const MAX_ROWS = 5000;
    public const MAX_CELLS = 200000;
    public const MAX_FIELD_LEN = 191;

    // Coded-price cipher (port of the Fazt Sale source app): a "code" column
    // from genuine-parts suppliers encodes a price. M-I-C-H-A-E-L-S-O-N maps
    // to 1-2-3-4-5-6-7-8-9-0, so e.g. MSN = 1-8-0 = ₱180.
    public const CODE_CIPHER = ['M' => '1', 'I' => '2', 'C' => '3', 'H' => '4', 'A' => '5', 'E' => '6', 'L' => '7', 'S' => '8', 'O' => '9', 'N' => '0'];

    /**
     * Decode a coded price string into a float, or null when it contains a
     * letter outside M-I-C-H-A-E-L-S-O-N (not decodable).
     */
    public static function codeToPrice(string $code): ?float
    {
        $clean = strtoupper(trim($code));
        $clean = (string)(preg_replace('/[^A-Z]/', '', $clean) ?? '');
        if ($clean === '') {
            return null;
        }
        $digits = '';
        foreach (str_split($clean) as $ch) {
            if (!isset(self::CODE_CIPHER[$ch])) {
                return null;
            }
            $digits .= self::CODE_CIPHER[$ch];
        }
        $n = (float)$digits;

        return is_nan($n) ? null : $n;
    }

    // ── XLSX parsing (ZipArchive + SimpleXML, no third-party dependency) ──

    /**
     * Parse an uploaded .xlsx file into worksheet grids.
     *
     * @return array{sheets:array<int,array{name:string,path:string}>, grids:array<string,array<int,array<int,string>>>}
     */
    public static function parseWorkbook(string $filePath, array $limits = []): array
    {
        $maxRows = (int)($limits['max_rows'] ?? self::MAX_ROWS);
        $maxCells = (int)($limits['max_cells'] ?? self::MAX_CELLS);

        if (!is_file($filePath)) {
            throw new \InvalidArgumentException('File is missing');
        }
        if (filesize($filePath) > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File exceeds the maximum size of 8 MB');
        }

        $sig = file_get_contents($filePath, false, null, 0, 4);
        if ($sig !== false && strncmp($sig, "PK\x03\x04", 4) === 0) {
            // valid zip
        } else {
            throw new \InvalidArgumentException('Not a valid .xlsx file (zip signature not found).');
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \InvalidArgumentException('Not a valid .xlsx file (could not open archive).');
        }

        try {
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($workbookXml === false || $relsXml === false) {
                // Fallback: single-sheet minimal workbook.
                $sheets = [];
                foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet.xml'] as $candidate) {
                    if ($zip->locateName($candidate) !== false) {
                        $sheets[] = ['name' => 'Sheet1', 'path' => $candidate];
                        break;
                    }
                }
                if ($sheets === []) {
                    throw new \InvalidArgumentException('Not a valid .xlsx file (no worksheets found).');
                }
                return ['sheets' => $sheets, 'grids' => []];
            }

            $sheets = self::listSheets($workbookXml, $relsXml);
            if ($sheets === []) {
                throw new \InvalidArgumentException('Not a valid .xlsx file (no sheets found).');
            }

            $grids = [];
            $totalCells = 0;
            foreach ($sheets as $sheet) {
                $path = (string)$sheet['path'];
                if (strpos($path, 'xl/') !== 0) {
                    $path = 'xl/' . ltrim($path, '/');
                }
                $sheetXml = $zip->getFromName($path);
                if ($sheetXml === false) {
                    continue;
                }
                $grid = self::parseSheetXml($sheetXml, $zip, $maxRows);
                $cells = 0;
                foreach ($grid as $row) {
                    $cells += count($row);
                }
                $totalCells += $cells;
                if ($totalCells > $maxCells) {
                    throw new \InvalidArgumentException('Workbook exceeds the maximum cell count');
                }
                $grids[$path] = $grid;
            }

            return ['sheets' => $sheets, 'grids' => $grids];
        } finally {
            $zip->close();
        }
    }

    private static function listSheets(string $workbookXml, string $relsXml): array
    {
        $sheets = [];

        $rels = new \SimpleXMLElement($relsXml);
        $rels->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relMap = [];
        foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $rel) {
            $id = (string)$rel['Id'];
            $target = (string)$rel['Target'];
            if ($id !== '' && $target !== '') {
                $relMap[$id] = $target;
            }
        }

        $wb = new \SimpleXMLElement($workbookXml);
        foreach ($wb->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [] as $sheetNode) {
            $name = (string)$sheetNode['name'];
            $rid = (string)($sheetNode->attributes('r', true)['id'] ?? '');
            $target = $relMap[$rid] ?? '';
            if ($target === '') {
                continue;
            }
            $sheets[] = ['name' => $name, 'path' => $target];
        }

        return $sheets;
    }

    private static function parseSheetXml(string $sheetXml, \ZipArchive $zip, int $maxRows): array
    {
        // Shared strings (namespace-agnostic selectors: SimpleXML prefix
        // registration does not reliably propagate to child-element xpath()).
        $shared = [];
        $sharedRaw = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedRaw !== false) {
            $ss = new \SimpleXMLElement($sharedRaw);
            foreach ($ss->xpath('//*[local-name()="si"]') ?: [] as $si) {
                $text = '';
                foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                    $text .= (string)$t;
                }
                $shared[] = $text;
            }
        }

        $xml = new \SimpleXMLElement($sheetXml);

        $grid = [];
        $rowNodes = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
        $rowCount = 0;
        foreach ($rowNodes as $rowNode) {
            if ($rowCount >= $maxRows) {
                break;
            }
            $rowData = [];
            foreach ($rowNode->xpath('*[local-name()="c"]') ?: [] as $cell) {
                $ref = (string)$cell['r'];
                $colIndex = 0;
                if (preg_match('/^([A-Za-z]+)/', $ref, $m)) {
                    $colIndex = self::colNameToIndex($m[1]);
                }
                $type = (string)$cell['t'];
                $value = '';
                if ($type === 's') {
                    $v = $cell->xpath('*[local-name()="v"]');
                    $idx = $v ? (int)trim((string)$v[0]) : 0;
                    $value = $shared[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $is = $cell->xpath('*[local-name()="is"]');
                    if ($is) {
                        foreach ($is[0]->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                            $value .= (string)$t;
                        }
                    }
                } elseif ($type === 'b') {
                    $v = $cell->xpath('*[local-name()="v"]');
                    $value = $v ? ((string)$v[0] === '1' ? 'TRUE' : 'FALSE') : '';
                } else {
                    $v = $cell->xpath('*[local-name()="v"]');
                    $value = $v ? (string)$v[0] : '';
                }
                $rowData[$colIndex] = self::cleanCell($value);
            }
            if ($rowData !== []) {
                ksort($rowData);
                // Preserve true spreadsheet column indices (A=0, B=1, …) instead
                // of compacting the row. Supplier pricelists are sparse — cells
                // are frequently missing — and mappings (template presets and
                // the wizard) are keyed by real column letter, so compacting
                // here would silently shift every mapped column after a gap.
                $grid[$rowCount] = $rowData;
            }
            $rowCount++;
        }

        return $grid;
    }

    private static function colNameToIndex(string $name): int
    {
        $index = 0;
        $name = strtoupper($name);
        $len = strlen($name);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($name[$i]) - 64);
        }
        return $index - 1;
    }

    private static function cleanCell(mixed $value): string
    {
        $value = trim((string)$value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;
        return $value;
    }

    // ── Row building & validation ────────────────────────────────────

    /**
     * Build import rows from a worksheet grid using a mapping.
     * $mapping maps 'part_number|description|cost|price|qty|code|code_attr'
     * OR 'custom:<label>' → column index.
     *
     * When a `code` column is mapped and no explicit `price` column is mapped,
     * the price is decoded from the coded price (MICHAELSON cipher); rows whose
     * code is not decodable get price 0 and a warning (they do not block commit,
     * matching the Fazt Sale source behaviour). `code_attr` never decodes: it
     * stores the raw code as an attribute and may be mapped alongside a price
     * column (brand template behavior).
     *
     * An optional $template (see ImportTemplateService) supplies part-number
     * synthesis for sheets that have no real part-number column: the part
     * number can be taken from the description column, or built by joining a
     * set of columns (composite, e.g. TIRE = SIZE + BRAND + PATTERN).
     *
     * @return array{rows:array, errors:array, warnings:array, new_count:int, existing_count:int}
     */
    public static function buildRows(array $ctx, int $branchId, int $brandId, array $grid, array $mapping, int $headerRow, int $dataStartRow, ?int $dataEndRow = null, ?array $template = null): array
    {
        $rows = [];
        $errors = [];
        $warnings = [];
        $seenParts = [];
        $existingMap = [];

        $priceExplicit = isset($mapping['price']);
        $codeMapped = isset($mapping['code']);
        $codeAttrMapped = isset($mapping['code_attr']);
        $pnSource = $template !== null ? (string)($template['part_number_source'] ?? 'column') : 'column';
        $pnCompositeCols = $template !== null && $pnSource === 'composite'
            ? (array)($template['part_number_cols'] ?? []) : [];
        $pnCompositeSep = $template !== null ? (string)($template['part_number_sep'] ?? ' ') : ' ';

        // Load existing products for this brand+branch for diff counts.
        $db = moto_db((int)$ctx['tenant_id']);
        $stmt = $db->prepare(
            'SELECT part_number, qty_on_hand FROM moto_products
             WHERE tenant_id = :tid AND branch_id = :bid AND brand_id = :brand'
        );
        $stmt->execute([
            ':tid'   => (int)$ctx['tenant_id'],
            ':bid'   => $branchId,
            ':brand' => $brandId,
        ]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $p) {
            $existingMap[strtolower(trim((string)$p['part_number']))] = $p;
        }

        $gridRows = array_values($grid);
        $rowCount = count($gridRows);
        $dataStart = max(0, $dataStartRow);
        $dataEnd = $dataEndRow === null ? $rowCount - 1 : max($dataStart, min($dataEndRow, $rowCount - 1));

        for ($i = $dataStart; $i <= $dataEnd; $i++) {
            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = 'Row limit of ' . self::MAX_ROWS . ' exceeded';
                break;
            }
            $sourceRow = $gridRows[$i] ?? [];
            $rowErrors = [];
            $row = [
                'row_index'   => $i,
                'part_number' => '',
                'description' => '',
                'cost'        => null,
                'price'       => null,
                'qty'         => null,
                'code'        => '',
                'extra'       => [],
            ];

            foreach ($mapping as $field => $col) {
                $col = (int)$col;
                $value = $sourceRow[$col] ?? '';
                if ($field === 'part_number') {
                    $row['part_number'] = trim($value);
                } elseif ($field === 'description') {
                    $row['description'] = trim($value);
                } elseif ($field === 'cost') {
                    $row['cost'] = self::parseNumeric($value);
                } elseif ($field === 'price') {
                    $row['price'] = self::parseNumeric($value);
                } elseif ($field === 'qty') {
                    $row['qty'] = self::parseNumeric($value);
                } elseif ($field === 'code' || $field === 'code_attr') {
                    // `code` (decode-eligible) and `code_attr` (stored as-is)
                    // both feed the product's code column; decoding happens
                    // below only for `code` when no price column is mapped.
                    $row['code'] = strtoupper(trim($value));
                } elseif (str_starts_with($field, 'custom:')) {
                    $label = substr($field, 7);
                    if (mb_strlen($label) <= 60) {
                        $row['extra'][$label] = trim($value);
                    }
                }
            }

            // Template part-number synthesis: sheets without a real part-number
            // column derive the part identity from the description column or a
            // composite of columns (the value is also used as the description
            // when no description column is mapped).
            if ($row['part_number'] === '') {
                if ($pnSource === 'description' && isset($mapping['description'])) {
                    $row['part_number'] = trim((string)($sourceRow[(int)$mapping['description']] ?? ''));
                } elseif ($pnSource === 'composite') {
                    $compositeParts = [];
                    foreach ($pnCompositeCols as $c) {
                        $compositeParts[] = trim((string)($sourceRow[(int)$c] ?? ''));
                    }
                    $compositeParts = array_values(array_filter(
                        $compositeParts,
                        static fn ($p): bool => $p !== ''
                    ));
                    $row['part_number'] = trim(implode($pnCompositeSep, $compositeParts));
                    if ($row['description'] === '' && $row['part_number'] !== '') {
                        $row['description'] = $row['part_number'];
                    }
                }
            }

            // Coded price: when only a code column is mapped, decode it into
            // the sell price (Fazt Sale semantics). Undecodable codes become
            // price 0 plus a warning. A stored code (code_attr) never decodes.
            if ($codeMapped && !$priceExplicit && !$codeAttrMapped && $row['code'] !== '') {
                $decoded = self::codeToPrice($row['code']);
                if ($decoded === null) {
                    $row['price'] = 0.0;
                    $warnings[] = 'Row ' . ($i + 2) . ' (' . $row['part_number'] . '): code "' . $row['code'] . '" contains a letter outside M-I-C-H-A-E-L-S-O-N and could not be decoded — price set to 0. Check the source data.';
                } else {
                    $row['price'] = $decoded;
                }
            }

            if ($row['part_number'] === '') {
                continue; // blank rows are skipped, not errors
            }
            if (mb_strlen($row['part_number']) > self::MAX_FIELD_LEN) {
                $rowErrors[] = 'Part number exceeds ' . self::MAX_FIELD_LEN . ' characters';
            }
            if (mb_strlen($row['description']) > self::MAX_FIELD_LEN) {
                $rowErrors[] = 'Description exceeds ' . self::MAX_FIELD_LEN . ' characters';
            }
            if ($row['cost'] !== null && ($row['cost'] < 0 || $row['cost'] > 99999999.99)) {
                $rowErrors[] = 'Cost is out of range';
            }
            if ($row['price'] !== null && ($row['price'] < 0 || $row['price'] > 99999999.99)) {
                $rowErrors[] = 'Price is out of range';
            }
            if ($row['qty'] !== null && ($row['qty'] < 0 || $row['qty'] > 99999999)) {
                $rowErrors[] = 'Quantity is out of range';
            }

            $key = strtolower($row['part_number']);
            if (isset($seenParts[$key])) {
                $rowErrors[] = 'Duplicate part number within this file';
            }
            $seenParts[$key] = true;

            $row['validation_status'] = $rowErrors === [] ? 'ok' : 'error';
            $row['validation_errors'] = $rowErrors;
            $row['is_new'] = !isset($existingMap[$key]);
            $rows[] = $row;
            if ($rowErrors !== []) {
                $errors[] = 'Row ' . ($i + 2) . ' (' . $row['part_number'] . '): ' . implode('; ', $rowErrors);
            }
        }

        $newCount = 0;
        $existingCount = 0;
        foreach ($rows as $row) {
            if ($row['validation_status'] === 'ok') {
                if ($row['is_new']) {
                    $newCount++;
                } else {
                    $existingCount++;
                }
            }
        }

        return [
            'rows'           => $rows,
            'errors'         => $errors,
            'warnings'       => $warnings,
            'new_count'      => $newCount,
            'existing_count' => $existingCount,
        ];
    }

    private static function parseNumeric(mixed $value): ?float
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $value = str_replace(',', '', trim((string)$value));
        if (!is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    /**
     * Auto-guess a column mapping from a header row using the legacy
     * substring heuristics (part/desc/cost/srp/qty/code).
     *
     * @return array<string,int> field → column index
     */
    public static function guessMappingFromHeaders(array $headerRow): array
    {
        $mapping = [];
        foreach ($headerRow as $col => $header) {
            $header = strtolower(trim((string)$header));
            if ($header === '') {
                continue;
            }
            if (!isset($mapping['part_number']) && self::headerMatches($header, ['part', 'part no', 'partno', 'sku'])) {
                $mapping['part_number'] = $col;
            } elseif (!isset($mapping['description']) && self::headerMatches($header, ['desc', 'part name', 'name', 'item', 'product'])) {
                $mapping['description'] = $col;
            } elseif (!isset($mapping['cost']) && self::headerMatches($header, ['cost', 'dealer', 'w/o vat']) && !self::headerMatches($header, ['total'])) {
                $mapping['cost'] = $col;
            } elseif (!isset($mapping['price']) && self::headerMatches($header, ['srp', 'sell', 'price', 'retail']) && !self::headerMatches($header, ['cost', 'total'])) {
                $mapping['price'] = $col;
            } elseif (!isset($mapping['qty']) && self::headerMatches($header, ['qty', 'quantity', 'stock', 'qoh', 'on hand'])) {
                $mapping['qty'] = $col;
            } elseif (!isset($mapping['code']) && self::headerMatches($header, ['code']) && !self::headerMatches($header, ['part', 'name'])) {
                $mapping['code'] = $col;
            }
        }
        // Unknown columns become custom fields under their header label.
        foreach ($headerRow as $col => $header) {
            $header = trim((string)$header);
            if ($header === '' || isset($mapping[$header]) || in_array($col, $mapping, true)) {
                continue;
            }
            $mapping['custom:' . $header] = $col;
        }

        return $mapping;
    }

    private static function headerMatches(string $header, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($header, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Score how closely a sheet name matches a template's preferred sheet name.
     * Names are normalized (lowercase, non-alphanumerics stripped) before
     * comparing. Scores: exact 100, prefix 90, contains 80, suffix 70, else 0.
     * Only >= 80 is treated as a match.
     */
    private static function sheetMatchScore(string $sheetName, string $templateSheet): int
    {
        $norm = static function (string $s): string {
            return (string)(preg_replace('/[^a-z0-9]+/', '', strtolower($s)) ?? '');
        };
        $a = $norm($sheetName);
        $b = $norm($templateSheet);
        if ($a === '' || $b === '') {
            return 0;
        }
        if ($a === $b) {
            return 100;
        }
        if (str_starts_with($a, $b)) {
            return 90;
        }
        if (str_contains($a, $b)) {
            return 80;
        }
        if (str_starts_with($b, $a)) {
            return 70;
        }
        return 0;
    }

    /**
     * Remove mapped columns that have no populated cell across the data range.
     * Supplier pricelists carry annotation columns that are blank for every
     * row (e.g. "DATE OF GIVEN PRICE"); importing them only produces empty
     * custom fields / null values, so they are dropped for a cleaner result.
     *
     * Identity columns are always kept:
     *   - the part_number column,
     *   - the description column when the template derives the part number
     *     from the description,
     *   - the composite part-number columns when the template builds the part
     *     number by joining columns.
     *
     * @param array<int, array<int, string>> $grid
     * @param array<string,int> $mapping field → column index
     * @return array<string,int>
     */
    private static function pruneEmptyMappingColumns(array $grid, array $mapping, int $dataStartRow, ?int $dataEndRow, ?array $template): array
    {
        $keep = [];
        if (isset($mapping['part_number'])) {
            $keep[(int)$mapping['part_number']] = true;
        }
        if ($template !== null) {
            $pnSource = (string)($template['part_number_source'] ?? 'column');
            if ($pnSource === 'description' && isset($mapping['description'])) {
                $keep[(int)$mapping['description']] = true;
            } elseif ($pnSource === 'composite') {
                foreach ((array)($template['part_number_cols'] ?? []) as $c) {
                    $keep[(int)$c] = true;
                }
            }
        }

        $gridRows = array_values($grid);
        $rowCount = count($gridRows);
        $dataEnd = $dataEndRow === null ? $rowCount - 1 : max($dataStartRow, min($dataEndRow, $rowCount - 1));

        $populated = [];
        for ($i = $dataStartRow; $i <= $dataEnd; $i++) {
            $row = $gridRows[$i] ?? [];
            foreach ($mapping as $field => $col) {
                $col = (int)$col;
                if (isset($row[$col]) && trim((string)$row[$col]) !== '') {
                    $populated[$col] = true;
                }
            }
        }

        $pruned = [];
        foreach ($mapping as $field => $col) {
            $col = (int)$col;
            if (isset($keep[$col]) || isset($populated[$col])) {
                $pruned[$field] = $col;
            }
        }

        return $pruned;
    }

    // ── Staging ─────────────────────────────────────────────────────

    /**
     * Stage a validated import. Persists the import header and rows in
     * 'staged' status; nothing touches inventory.
     *
     * When $template (see ImportTemplateService) is supplied:
     *   - the preferred sheet name selects the sheet when it exists,
     *   - a missing $mapping is built from the template (including header and
     *     data-start rows),
     *   - part-number synthesis (description / composite) is applied, and
     *   - a `code_attr` mapping may coexist with a Sell Price column.
     *
     * @return array{import_id:int, rows:array, new_count:int, existing_count:int, errors:array, warnings:array}
     */
    public static function stage(array $ctx, int $branchId, int $brandId, string $filePath, string $filename, string $mime, ?array $mapping = null, int $sheetIndex = 0, int $headerRow = 0, int $dataStartRow = 1, ?int $dataEndRow = null, ?string $idempotencyKey = null, ?array $template = null): array
    {
        $branchId = moto_require_write_branch($ctx, $branchId);
        $tenantId = (int)$ctx['tenant_id'];
        $db = moto_db($tenantId);

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension !== 'xlsx') {
            throw new \InvalidArgumentException('Only .xlsx files are supported');
        }
        if ($mime !== '' && stripos($mime, 'spreadsheet') === false && stripos($mime, 'octet-stream') === false) {
            throw new \InvalidArgumentException('File MIME type is not a spreadsheet');
        }
        if (!is_file($filePath) || filesize($filePath) === 0) {
            throw new \InvalidArgumentException('Uploaded file is empty');
        }
        if (filesize($filePath) > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File exceeds the maximum size of 8 MB');
        }
        if (CatalogService::brandById($ctx, $brandId) === null) {
            throw new \InvalidArgumentException('Brand not found');
        }

        $parsed = self::parseWorkbook($filePath);
        $sheets = $parsed['sheets'];
        if ($sheets === []) {
            throw new \InvalidArgumentException('Workbook has no sheets');
        }

        // A template may carry a preferred sheet name (e.g. "HONDA GEN").
        // Matching is fuzzy and case/spacing tolerant: exact > prefix >
        // contains, mirroring the wizard's auto-match so API-driven imports
        // pick the same sheet a user would.
        if ($template !== null) {
            $prefSheet = (string)($template['sheet'] ?? '');
            if ($prefSheet !== '') {
                $bestIdx = null;
                $bestScore = 0;
                foreach ($sheets as $idx => $s) {
                    $score = self::sheetMatchScore((string)$s['name'], $prefSheet);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestIdx = $idx;
                    }
                }
                if ($bestIdx !== null && $bestScore >= 80) {
                    $sheetIndex = $bestIdx;
                }
            }
        }

        $sheetIndex = max(0, min(count($sheets) - 1, $sheetIndex));
        $sheetPath = (string)$sheets[$sheetIndex]['path'];
        if (strpos($sheetPath, 'xl/') !== 0) {
            $sheetPath = 'xl/' . ltrim($sheetPath, '/');
        }
        $grid = $parsed['grids'][$sheetPath] ?? [];
        if ($grid === []) {
            throw new \InvalidArgumentException('Selected sheet is empty');
        }

        // When the client relies on the template entirely (no explicit mapping
        // or row range), apply the template's mapping and header/data rows.
        $templateMappingUsed = false;
        if (($mapping === null || $mapping === []) && $template !== null) {
            $templateMapping = $template['mapping'] ?? null;
            if (is_array($templateMapping) && $templateMapping !== []) {
                $mapping = [];
                foreach ($templateMapping as $col => $field) {
                    $mapping[(string)$field] = (int)$col;
                }
                $templateMappingUsed = true;
                $headerRow = max(0, ((int)($template['header_row'] ?? 1)) - 1);
                $dataStartRow = max(0, ((int)($template['data_start_row'] ?? 2)) - 1);
            }
        }

        // Auto-guess the mapping from the header row when none is supplied.
        if (($mapping === null || $mapping === []) && !$templateMappingUsed) {
            $headerRow = max(0, min(count($grid) - 1, $headerRow));
            $mapping = self::guessMappingFromHeaders($grid[$headerRow] ?? []);
        }

        // Require a part_number mapping and at most one of each standard field.
        $standardFields = ['part_number', 'description', 'cost', 'price', 'qty', 'code', 'code_attr'];
        $seen = [];
        foreach ($mapping as $field => $col) {
            if (in_array($field, $standardFields, true)) {
                if (isset($seen[$field])) {
                    throw new \InvalidArgumentException('Duplicate mapping for ' . $field);
                }
                $seen[$field] = true;
            }
        }
        if (!isset($seen['part_number'])) {
            $pnSynthesized = $template !== null
                && in_array((string)($template['part_number_source'] ?? ''), ['description', 'composite'], true);
            if (!$pnSynthesized) {
                throw new \InvalidArgumentException('Exactly one Part No. column is required');
            }
        }
        // Sell Price and Code Price both feed the item's price — never both
        // (matches the Fazt Sale mapping wizard validation). A stored code
        // (code_attr) does not conflict with a Sell Price column.
        if (isset($seen['price']) && isset($seen['code'])) {
            throw new \InvalidArgumentException("Sell Price and Code Price both map to the item's price — pick one");
        }
        if (isset($seen['code']) && isset($seen['code_attr'])) {
            throw new \InvalidArgumentException("A column cannot be both a Code Price and a stored code — pick one");
        }

        // Drop fully-empty columns from the import: a mapped column with no
        // populated cell across the data range contributes nothing (empty
        // custom fields, null price/qty) — pruning keeps the staged data and
        // the product's extra JSON clean. Identity columns (the part-number
        // column, or the description/composite part-number sources) are always
        // kept so the part identity survives.
        $mapping = self::pruneEmptyMappingColumns($grid, $mapping, $dataStartRow, $dataEndRow, $template);

        $built = self::buildRows($ctx, $branchId, $brandId, $grid, $mapping, $headerRow, $dataStartRow, $dataEndRow, $template);
        if ($built['rows'] === []) {
            throw new \InvalidArgumentException('No data rows found in the selected range');
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO moto_imports
                    (tenant_id, branch_id, brand_id, filename, file_size, mime, status, row_count, new_count, existing_count, error_report, idempotency_key, created_by, created_by_name)
                 VALUES (:tid, :bid, :brand, :fname, :fsize, :mime, :status, :rcount, :new, :existing, :errors, :idem, :uid, :actor)'
            );
            $stmt->execute([
                ':tid'      => $tenantId,
                ':bid'      => $branchId,
                ':brand'    => $brandId,
                ':fname'    => substr($filename, 0, 255),
                ':fsize'    => (int)filesize($filePath),
                ':mime'     => substr($mime, 0, 120),
                ':status'   => 'staged',
                ':rcount'   => count($built['rows']),
                ':new'      => $built['new_count'],
                ':existing' => $built['existing_count'],
                ':errors'   => $built['errors'] === [] ? null : json_encode($built['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':idem'     => $idempotencyKey !== null ? $idempotencyKey : null,
                ':uid'      => (int)($ctx['user_id'] ?? 0) ?: null,
                ':actor'    => (string)($ctx['actor_name'] ?? ''),
            ]);
            $importId = (int)$db->lastInsertId();

            $rowStmt = $db->prepare(
                'INSERT INTO moto_import_rows
                    (tenant_id, import_id, row_index, part_number, description, cost, price, qty, code, extra, validation_status, validation_errors)
                 VALUES (:tid, :iid, :ridx, :part, :desc, :cost, :price, :qty, :code, :extra, :vstatus, :verrors)'
            );
            foreach ($built['rows'] as $row) {
                $rowStmt->execute([
                    ':tid'     => $tenantId,
                    ':iid'     => $importId,
                    ':ridx'    => (int)$row['row_index'],
                    ':part'    => $row['part_number'],
                    ':desc'    => $row['description'],
                    ':cost'    => $row['cost'],
                    ':price'   => $row['price'],
                    ':qty'     => $row['qty'],
                    ':code'    => $row['code'],
                    ':extra'   => $row['extra'] !== [] ? json_encode($row['extra'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                    ':vstatus' => $row['validation_status'],
                    ':verrors'=> $row['validation_errors'] !== [] ? json_encode($row['validation_errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ]);
            }

            // Audit the staged import atomically with the header + rows.
            moto_audit($ctx, 'moto_inventory.import.staged', 'moto_import', (string)$importId, null, [
                'branch_id' => $branchId, 'filename' => $filename, 'new' => $built['new_count'], 'existing' => $built['existing_count'],
                'warnings' => $built['warnings'] === [] ? null : $built['warnings'],
            ], $branchId, $idempotencyKey, $db);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return [
            'import_id'      => $importId,
            'rows'           => $built['rows'],
            'new_count'      => $built['new_count'],
            'existing_count' => $built['existing_count'],
            'errors'         => $built['errors'],
            'warnings'       => $built['warnings'],
        ];
    }

    /**
     * Commit a staged import atomically. Repeat-safe: a committed import is
     * returned as-is on a second call.
     *
     * @param bool $overwriteQty When true, existing products get the file quantity.
     */
    public static function commit(array $ctx, int $importId, bool $overwriteQty = false): array
    {
        $tenantId = (int)$ctx['tenant_id'];
        $db = moto_db($tenantId);

        $stmt = $db->prepare('SELECT * FROM moto_imports WHERE tenant_id = :tid AND id = :id LIMIT 1');
        $stmt->execute([':tid' => $tenantId, ':id' => $importId]);
        $import = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($import)) {
            throw new \InvalidArgumentException('Import not found');
        }
        moto_require_write_branch($ctx, (int)$import['branch_id']);
        if ($import['status'] === 'committed') {
            // Repeat-safe: return the recorded outcome.
            return [
                'import_id'      => $importId,
                'status'         => 'committed',
                'new_count'      => (int)$import['new_count'],
                'existing_count' => (int)$import['existing_count'],
                'committed_at'   => (string)$import['committed_at'],
            ];
        }
        if ($import['status'] !== 'staged') {
            throw new \RuntimeException('Import is not in a committable state');
        }

        $branchId = (int)$import['branch_id'];
        $brandId = (int)$import['brand_id'];
        moto_require_write_branch($ctx, $branchId);

        // Reject commit when validation errors exist unless force flag; default: block.
        if ($import['error_report'] !== null) {
            throw new \RuntimeException('Import has validation errors; fix and re-upload');
        }

        $rowStmt = $db->prepare('SELECT * FROM moto_import_rows WHERE tenant_id = :tid AND import_id = :iid ORDER BY id');
        $rowStmt->execute([':tid' => $tenantId, ':iid' => $importId]);
        $rows = $rowStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            throw new \RuntimeException('Import has no rows to commit');
        }

        $newCount = 0;
        $existingCount = 0;

        $db->beginTransaction();
        try {
            // Serialize commits of the same staged import. The preliminary
            // read above supports friendly validation, but only this locked
            // state is authoritative for the mutation.
            $lockedStmt = $db->prepare(
                'SELECT status, new_count, existing_count, committed_at
                 FROM moto_imports WHERE tenant_id = :tid AND id = :id
                 LIMIT 1 FOR UPDATE'
            );
            $lockedStmt->execute([':tid' => $tenantId, ':id' => $importId]);
            $locked = $lockedStmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($locked)) {
                throw new \InvalidArgumentException('Import not found');
            }
            if ($locked['status'] === 'committed') {
                $db->rollBack();
                return [
                    'import_id' => $importId,
                    'status' => 'committed',
                    'new_count' => (int)$locked['new_count'],
                    'existing_count' => (int)$locked['existing_count'],
                    'committed_at' => (string)$locked['committed_at'],
                ];
            }
            if ($locked['status'] !== 'staged') {
                throw new \RuntimeException('Import is not in a committable state');
            }

            foreach ($rows as $row) {
                if ($row['validation_status'] !== 'ok') {
                    throw new \RuntimeException('Import contains invalid rows; fix and re-upload');
                }
                $extra = $row['extra'] !== null ? json_decode((string)$row['extra'], true) : [];
                $extra = is_array($extra) ? $extra : [];

                $existing = CatalogService::productByKey($ctx, $branchId, $brandId, (string)$row['part_number']);
                if ($existing !== null) {
                    $existingCount++;
                    $fields = ['description = :desc', 'cost = :cost', 'price = :price', 'code = :code', 'extra = :extra'];
                    $params = [
                        ':desc' => (string)$row['description'],
                        ':cost' => moto_money_float($row['cost']),
                        ':price' => moto_money_float($row['price']),
                        ':code' => (string)$row['code'],
                        ':extra' => $extra !== [] ? json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        ':tid' => $tenantId,
                        ':id' => (int)$existing['id'],
                    ];
                    $db->prepare('UPDATE moto_products SET ' . implode(', ', $fields) . ' WHERE tenant_id = :tid AND id = :id')
                        ->execute($params);

                    if ($overwriteQty && $row['qty'] !== null) {
                        $delta = moto_qty($row['qty']) - (float)$existing['qty_on_hand'];
                        if ($delta != 0) {
                            StockService::applyDelta(
                                $db, $ctx, $branchId, (int)$existing['id'], $delta,
                                StockService::TYPE_IMPORT,
                                'moto_import', $importId,
                                'IMPORT_QTY_UPDATE:' . $existing['part_number'],
                                'import:' . $importId,
                                true
                            );
                        }
                    }
                } else {
                    $newCount++;
                    $productId = self::insertImportedProduct($db, $ctx, $branchId, $brandId, $row);
                    if ($row['qty'] !== null && moto_qty($row['qty']) > 0) {
                        StockService::applyDelta(
                            $db, $ctx, $branchId, $productId, moto_qty($row['qty']),
                            StockService::TYPE_IMPORT,
                            'moto_import', $importId,
                            'IMPORT_NEW_PART:' . $row['part_number'],
                            'import:' . $importId,
                            true
                        );
                    }
                }
            }

            $db->prepare(
                'UPDATE moto_imports SET status = :status, committed_at = :cat, new_count = :new, existing_count = :existing
                 WHERE tenant_id = :tid AND id = :id'
            )->execute([
                ':status' => 'committed',
                ':cat'    => date('Y-m-d H:i:s'),
                ':new'    => $newCount,
                ':existing' => $existingCount,
                ':tid'    => $tenantId,
                ':id'     => $importId,
            ]);

            $result = [
                'import_id'      => $importId,
                'status'         => 'committed',
                'new_count'      => $newCount,
                'existing_count' => $existingCount,
                'committed_at'   => date('Y-m-d H:i:s'),
            ];

            // Audit commits atomically with the import (never commit the
            // inventory change and then fail to record it).
            moto_audit($ctx, 'moto_inventory.import.committed', 'moto_import', (string)$importId, null, $result, $branchId, $import['idempotency_key'] ?: null, $db);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Events are post-commit and best-effort (moto_emit_event never throws).
        moto_emit_event('moto_inventory.import.committed', [
            'tenant_id' => $tenantId, 'branch_id' => $branchId, 'import_id' => $importId,
            'new' => $newCount, 'existing' => $existingCount,
        ]);

        return $result;
    }

    private static function insertImportedProduct(\Ikabud\Kernel\Contracts\ModuleDB $db, array $ctx, int $branchId, int $brandId, array $row): int
    {
        $extra = $row['extra'] !== null ? json_decode((string)$row['extra'], true) : [];
        $extra = is_array($extra) ? $extra : [];

        $stmt = $db->prepare(
            'INSERT INTO moto_products
                (tenant_id, branch_id, brand_id, part_number, description, code, cost, price, qty_on_hand, extra)
             VALUES (:tid, :bid, :brand, :part, :desc, :code, :cost, :price, 0, :extra)'
        );
        $stmt->execute([
            ':tid'   => (int)$ctx['tenant_id'],
            ':bid'   => $branchId,
            ':brand' => $brandId,
            ':part'  => (string)$row['part_number'],
            ':desc'  => (string)$row['description'],
            ':code'  => (string)$row['code'],
            ':cost'  => moto_money_float($row['cost']),
            ':price' => moto_money_float($row['price']),
            ':extra' => $extra !== [] ? json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Imports list for the import page.
     */
    public static function imports(array $ctx, array $filters = []): array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $tenantId = (int)$ctx['tenant_id'];

        $where = ['i.tenant_id = :tid'];
        $params = [':tid' => $tenantId];
        if (!empty($filters['branch_id']) && (int)$filters['branch_id'] > 0) {
            $where[] = 'i.branch_id = :bid';
            $params[':bid'] = (int)$filters['branch_id'];
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $db->query(
            "SELECT i.*, b.name AS brand_name, br.name AS branch_name
             FROM moto_imports i
             JOIN moto_brands b ON b.id = i.brand_id
             JOIN moto_branches br ON br.id = i.branch_id
             WHERE {$whereSql}
             ORDER BY i.id DESC
             LIMIT 100",
            $params
        );

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['error_report'] = $row['error_report'] !== null ? json_decode((string)$row['error_report'], true) : [];
        }
        unset($row);

        return $rows;
    }

    // ── Versioned export / import (safe, business keys only) ────────

    /**
     * Export module-owned data for a branch using public business keys.
     * Never exports internal ids, tenant ids, actors, or audit rows.
     */
    public static function export(array $ctx, int $branchId, string $scope = 'full'): array
    {
        $branchId = moto_require_write_branch($ctx, $branchId);
        $tenantId = (int)$ctx['tenant_id'];
        $db = moto_db($tenantId);

        $brands = [];
        $products = [];

        $brandStmt = $db->query(
            'SELECT name, archived, trashed FROM moto_brands WHERE tenant_id = :tid ORDER BY name',
            [':tid' => $tenantId]
        );
        foreach ($brandStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $b) {
            $brands[] = ['name' => $b['name'], 'archived' => (int)$b['archived'], 'trashed' => (int)$b['trashed']];
        }

        $productStmt = $db->query(
            'SELECT p.part_number, p.description, p.code, p.cost, p.price, p.qty_on_hand, p.extra, p.archived, b.name AS brand
             FROM moto_products p
             JOIN moto_brands b ON b.id = p.brand_id
             WHERE p.tenant_id = :tid AND p.branch_id = :bid
             ORDER BY b.name, p.part_number',
            [':tid' => $tenantId, ':bid' => $branchId]
        );
        foreach ($productStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $p) {
            $products[] = [
                'brand'       => $p['brand'],
                'part_number' => $p['part_number'],
                'description' => $p['description'],
                'code'        => $p['code'],
                'cost'        => (float)$p['cost'],
                'price'       => (float)$p['price'],
                'qty'         => (float)$p['qty_on_hand'],
                'extra'       => $p['extra'] !== null ? (json_decode((string)$p['extra'], true) ?: []) : [],
                'archived'    => (int)$p['archived'],
            ];
        }

        $payload = [
            'version'     => self::BACKUP_VERSION,
            'module'      => 'moto-inventory',
            'exported_at' => gmdate('c'),
            'tenant_id'   => $tenantId,
            'branch_id'   => $branchId,
            'data'        => [
                'brands'   => $brands,
                'products' => $products,
            ],
        ];

        // GET export is intentionally read-only: it never writes business data,
        // audit rows, or backup history. (A separate POST-based backup-recording
        // endpoint would own any such metadata writes.)
        return $payload;
    }

    /**
     * Import a versioned backup. Refuses internal ids/tenants/audit rows and
     * only merges by public business keys. Repeat-safe via idempotency key.
     *
     * @return array{brands_created:int, products_created:int, products_updated:int}
     */
    public static function importBackup(array $ctx, int $branchId, array $payload, ?string $idempotencyKey = null): array
    {
        $branchId = moto_require_write_branch($ctx, $branchId);
        $tenantId = (int)$ctx['tenant_id'];
        $db = moto_db($tenantId);

        if (($payload['module'] ?? '') !== 'moto-inventory') {
            throw new \InvalidArgumentException('Not a Moto Inventory backup');
        }
        if ((string)($payload['version'] ?? '') !== self::BACKUP_VERSION) {
            throw new \InvalidArgumentException('Unsupported backup version');
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Backup is missing data');
        }

        // Reject arbitrary/legacy restore shapes.
        foreach (['stores', 'meta', 'auditLog', 'backupParts', 'syncQueue'] as $forbidden) {
            if (array_key_exists($forbidden, $payload) || array_key_exists($forbidden, $data)) {
                throw new \InvalidArgumentException('Legacy full-database restore is not supported');
            }
        }

        $request = ['branch_id' => $branchId, 'brands' => $data['brands'] ?? [], 'products' => $data['products'] ?? []];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $cached = moto_idem_fetch($ctx, $idempotencyKey, 'backup.import', $request, $branchId);
            if ($cached !== null) {
                return $cached;
            }
        }

        $brandsCreated = 0;
        $productsCreated = 0;
        $productsUpdated = 0;

        $db->beginTransaction();
        try {
            // Claim the idempotency key atomically before any write; a
            // concurrent retry waits for and returns our committed response.
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                if (!moto_idem_claim($db, $ctx, $idempotencyKey, 'backup.import', $request, $branchId)) {
                    $db->rollBack();
                    return moto_idem_wait_fetch($ctx, $idempotencyKey, 'backup.import', $request, $branchId);
                }
            }

            $brandNameToId = [];
            $brandStmt = $db->query('SELECT id, name FROM moto_brands WHERE tenant_id = :tid', [':tid' => $tenantId]);
            foreach ($brandStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $b) {
                $brandNameToId[$b['name']] = (int)$b['id'];
            }

            foreach ($data['brands'] ?? [] as $brand) {
                $name = trim((string)($brand['name'] ?? ''));
                if ($name === '' || isset($brandNameToId[$name])) {
                    continue;
                }
                $stmt = $db->prepare('INSERT INTO moto_brands (tenant_id, name, archived, trashed) VALUES (:tid, :name, :arch, :trash)');
                $stmt->execute([
                    ':tid' => $tenantId, ':name' => $name,
                    ':arch' => (int)($brand['archived'] ?? 0), ':trash' => (int)($brand['trashed'] ?? 0),
                ]);
                $brandNameToId[$name] = (int)$db->lastInsertId();
                $brandsCreated++;
            }

            $productStmt = $db->prepare(
                'INSERT INTO moto_products
                    (tenant_id, branch_id, brand_id, part_number, description, code, cost, price, qty_on_hand, extra, archived)
                 VALUES (:tid, :bid, :brand, :part, :desc, :code, :cost, :price, :qty, :extra, :arch)'
            );
            foreach ($data['products'] ?? [] as $product) {
                $part = trim((string)($product['part_number'] ?? ''));
                $brandName = trim((string)($product['brand'] ?? ''));
                if ($part === '' || $brandName === '' || !isset($brandNameToId[$brandName])) {
                    continue;
                }
                $qty = moto_qty($product['qty'] ?? 0);
                $existing = CatalogService::productByKey($ctx, $branchId, $brandNameToId[$brandName], $part);
                if ($existing !== null) {
                    $productsUpdated++;
                    $db->prepare(
                        'UPDATE moto_products SET description = :desc, code = :code, cost = :cost, price = :price, extra = :extra, archived = :arch
                         WHERE tenant_id = :tid AND id = :id'
                    )->execute([
                        ':desc' => substr(trim((string)($product['description'] ?? '')), 0, 191),
                        ':code' => strtoupper(substr(trim((string)($product['code'] ?? '')), 0, 64)),
                        ':cost' => moto_money_float($product['cost'] ?? 0),
                        ':price' => moto_money_float($product['price'] ?? 0),
                        ':extra' => isset($product['extra']) && is_array($product['extra']) && $product['extra'] !== [] ? json_encode($product['extra'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        ':arch' => (int)($product['archived'] ?? 0),
                        ':tid' => $tenantId,
                        ':id' => (int)$existing['id'],
                    ]);
                    if ($qty != (float)$existing['qty_on_hand']) {
                        StockService::applyDelta(
                            $db, $ctx, $branchId, (int)$existing['id'], $qty - (float)$existing['qty_on_hand'],
                            StockService::TYPE_RESTORE,
                            'moto_backup', (int)($payload['branch_id'] ?? 0) ?: null,
                            'BACKUP_IMPORT:' . $part,
                            'backup:' . ($idempotencyKey ?? $part),
                            true
                        );
                    }
                } else {
                    $productsCreated++;
                    $productStmt->execute([
                        ':tid' => $tenantId, ':bid' => $branchId, ':brand' => $brandNameToId[$brandName],
                        ':part' => $part,
                        ':desc' => substr(trim((string)($product['description'] ?? '')), 0, 191),
                        ':code' => strtoupper(substr(trim((string)($product['code'] ?? '')), 0, 64)),
                        ':cost' => moto_money_float($product['cost'] ?? 0),
                        ':price' => moto_money_float($product['price'] ?? 0),
                        ':qty' => $qty,
                        ':extra' => isset($product['extra']) && is_array($product['extra']) && $product['extra'] !== [] ? json_encode($product['extra'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        ':arch' => (int)($product['archived'] ?? 0),
                    ]);
                    $newId = (int)$db->lastInsertId();
                    if ($qty > 0) {
                        StockService::applyDelta(
                            $db, $ctx, $branchId, $newId, $qty,
                            StockService::TYPE_RESTORE,
                            'moto_backup', (int)($payload['branch_id'] ?? 0) ?: null,
                            'BACKUP_IMPORT:' . $part,
                            'backup:' . ($idempotencyKey ?? $part),
                            true
                        );
                    }
                }
            }

            $result = [
                'brands_created'   => $brandsCreated,
                'products_created' => $productsCreated,
                'products_updated' => $productsUpdated,
            ];

            // Audit + idempotency response commit atomically with the import.
            moto_audit($ctx, 'moto_inventory.data.imported', null, null, null, $result + ['branch_id' => $branchId], $branchId, $idempotencyKey, $db);
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                moto_idem_complete($db, $ctx, $idempotencyKey, 'backup.import', $result, $branchId);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * Produce a downloadable error report (CSV) for a staged import.
     */
    public static function errorReport(array $ctx, int $importId): string
    {
        $tenantId = (int)$ctx['tenant_id'];
        $db = moto_db($tenantId);

        $stmt = $db->prepare('SELECT * FROM moto_imports WHERE tenant_id = :tid AND id = :id LIMIT 1');
        $stmt->execute([':tid' => $tenantId, ':id' => $importId]);
        $import = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($import)) {
            throw new \InvalidArgumentException('Import not found');
        }
        moto_require_write_branch($ctx, (int)$import['branch_id']);

        $rowStmt = $db->prepare(
            'SELECT row_index, part_number, description, validation_errors FROM moto_import_rows
             WHERE tenant_id = :tid AND import_id = :iid AND validation_status = :status ORDER BY id'
        );
        $rowStmt->execute([':tid' => $tenantId, ':iid' => $importId, ':status' => 'error']);
        $rows = $rowStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $lines = ["Row,Part Number,Description,Errors"];
        foreach ($rows as $row) {
            $errors = $row['validation_errors'] !== null ? json_decode((string)$row['validation_errors'], true) : [];
            $lines[] = implode(',', [
                (int)$row['row_index'] + 2,
                self::csvCell($row['part_number']),
                self::csvCell($row['description']),
                self::csvCell(is_array($errors) ? implode('; ', $errors) : ''),
            ]);
        }

        return implode("\n", $lines);
    }

    private static function csvCell(mixed $value): string
    {
        $value = (string)$value;
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
