<?php

declare(strict_types=1);

/**
 * Moto Inventory — ImportTemplateService
 *
 * Brand import mapping templates. A template is a named, reusable mapping
 * preset that drives the pricelist import wizard: which sheet column maps to
 * which product field, the preferred sheet name, header/data rows, how the
 * CODE column is treated, and how the part number is derived when the source
 * sheet has no real part-number column.
 *
 * Two kinds:
 *   - presets  — bundled, read-only layouts derived from the "MOM CYCLE STOCK
 *                INVENTORY" supplier workbook (HONDA/YAMAHA/SUZUKI/KAWASAKI
 *                genuine + replacement, HAOJUE, SKYGO, CHINA MOTORS, BAJAJ RE,
 *                TIRE, COMMON PARTS, MULTICAB, TOOLS).
 *   - custom   — user-created, tenant-scoped templates stored in
 *                moto_import_templates for brands not covered by a preset.
 *
 * The mapping uses the same field keys as ImportService::stage():
 *   part_number | description | cost | price | qty | code | code_attr | custom:<label>
 * where `code` decodes a MICHAELSON coded price (no explicit price column)
 * and `code_attr` stores the raw code as an attribute alongside a price.
 */
final class ImportTemplateService
{
    // Field key → human role label (also used by the wizard JS).
    public const FIELD_LABELS = [
        'part_number' => 'Part No.',
        'description' => 'Description',
        'cost'        => 'Cost Price',
        'price'       => 'Sell Price',
        'qty'         => 'Quantity',
        'code'        => 'Code Price',
        'code_attr'   => 'Code (store)',
    ];

    // Part-number synthesis strategies.
    public const PN_COLUMN = 'column';        // a part_number column is mapped
    public const PN_DESCRIPTION = 'description'; // part number := description value
    public const PN_COMPOSITE = 'composite';  // part number := joined columns

    /**
     * Bundled per-brand presets (one per MOM CYCLE supplier sheet).
     * Column indices are 0-based (A=0, B=1, …). The header row and the mapping
     * follow the *declared* sheet layout; users can still adjust the mapping in
     * the wizard before staging because source sheets are human-maintained and
     * columns occasionally shift.
     *
     * @var array<string,array>
     */
    public const PRESETS = [
        'honda_gen' => [
            'key' => 'honda_gen', 'name' => 'HONDA GEN', 'kind' => 'preset',
            'sheet' => 'HONDA GEN', 'brand_hint' => 'HONDA GEN',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'part_number',
                3 => 'qty', 4 => 'custom:Qty Stock', 5 => 'code_attr', 6 => 'price',
                7 => 'custom:Date of Given Price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_COLUMN,
        ],
        'yamaha_gen' => [
            'key' => 'yamaha_gen', 'name' => 'YAMAHA GEN', 'kind' => 'preset',
            'sheet' => 'YAMAHA GEN', 'brand_hint' => 'YAMAHA GEN',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'part_number',
                3 => 'qty', 4 => 'custom:Qty Stock', 5 => 'code_attr', 6 => 'price',
                7 => 'custom:Date of Given Price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_COLUMN,
        ],
        'suzuki_gen' => [
            'key' => 'suzuki_gen', 'name' => 'SUZUKI GEN', 'kind' => 'preset',
            'sheet' => 'SUZUKI GEN', 'brand_hint' => 'SUZUKI GEN',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'part_number',
                3 => 'custom:Size', 4 => 'custom:Color', 5 => 'qty',
                6 => 'custom:Qty Stock', 7 => 'code_attr', 8 => 'price',
                9 => 'custom:Date of Given Price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_COLUMN,
        ],
        'kawasaki_gen' => [
            'key' => 'kawasaki_gen', 'name' => 'KAWASAKI GEN', 'kind' => 'preset',
            'sheet' => 'KAWASAKI GEN', 'brand_hint' => 'KAWASAKI GEN',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'part_number',
                3 => 'custom:Size', 4 => 'custom:Color', 5 => 'qty',
                6 => 'custom:Qty Stock', 7 => 'code_attr', 8 => 'price',
                9 => 'custom:Date of Given Price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_COLUMN,
        ],
        'haojue' => [
            'key' => 'haojue', 'name' => 'HAOJUE', 'kind' => 'preset',
            'sheet' => 'HAOJUE', 'brand_hint' => 'HAOJUE',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'part_number',
                3 => 'custom:Size', 4 => 'custom:Color', 5 => 'qty',
                6 => 'custom:Qty Stock', 7 => 'code_attr', 8 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_COLUMN,
        ],
        'honda_rep' => [
            'key' => 'honda_rep', 'name' => 'HONDA REP', 'kind' => 'preset',
            'sheet' => 'HONDA REP', 'brand_hint' => 'HONDA REP',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'custom:Brand',
                3 => 'custom:Size', 4 => 'custom:Color', 5 => 'qty',
                6 => 'custom:Qty Stock', 7 => 'code_attr', 8 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_DESCRIPTION,
        ],
        'skygo' => [
            'key' => 'skygo', 'name' => 'SKYGO', 'kind' => 'preset',
            'sheet' => 'SKYGO', 'brand_hint' => 'SKYGO',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'part_number',
                3 => 'custom:Size', 4 => 'custom:Color', 5 => 'qty',
                6 => 'custom:Qty Stock', 7 => 'code_attr', 8 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_COLUMN,
        ],
        'yamaha_rep' => [
            'key' => 'yamaha_rep', 'name' => 'YAMAHA REP', 'kind' => 'preset',
            'sheet' => 'YAMAHA REP', 'brand_hint' => 'YAMAHA REP',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'custom:Brand',
                3 => 'custom:Size', 4 => 'custom:Color', 5 => 'qty',
                6 => 'custom:Qty Stock', 7 => 'code_attr', 8 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_DESCRIPTION,
        ],
        'china_motors' => [
            'key' => 'china_motors', 'name' => 'CHINA MOTORS', 'kind' => 'preset',
            'sheet' => 'CHINA MOTORS', 'brand_hint' => 'CHINA MOTORS',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'custom:Brand',
                3 => 'custom:Size', 4 => 'custom:Color', 5 => 'qty',
                6 => 'custom:Qty Stock', 7 => 'code_attr', 8 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_DESCRIPTION,
        ],
        'kawasaki_rep' => [
            'key' => 'kawasaki_rep', 'name' => 'KAWASAKI REP', 'kind' => 'preset',
            'sheet' => 'KAWASAKI REP', 'brand_hint' => 'KAWASAKI REP',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'custom:Brand',
                3 => 'custom:Size', 4 => 'custom:Color', 5 => 'qty',
                6 => 'custom:Qty Stock', 7 => 'code_attr', 8 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_DESCRIPTION,
        ],
        'suzuki_rep' => [
            'key' => 'suzuki_rep', 'name' => 'SUZUKI REP', 'kind' => 'preset',
            'sheet' => 'SUZUKI REP', 'brand_hint' => 'SUZUKI REP',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'custom:Brand',
                3 => 'custom:Size', 4 => 'custom:Color', 5 => 'qty',
                6 => 'custom:Qty Stock', 7 => 'code_attr', 8 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_DESCRIPTION,
        ],
        'bajaj_re' => [
            'key' => 'bajaj_re', 'name' => 'BAJAJ RE', 'kind' => 'preset',
            'sheet' => 'BAJAJ RE', 'brand_hint' => 'BAJAJ RE',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Brand', 2 => 'qty',
                3 => 'custom:Qty Stock', 4 => 'code_attr', 5 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_DESCRIPTION,
        ],
        'tire' => [
            'key' => 'tire', 'name' => 'TIRE', 'kind' => 'preset',
            'sheet' => 'TIRE', 'brand_hint' => 'TIRE',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'custom:Size', 1 => 'custom:Brand', 2 => 'custom:Pattern',
                3 => 'custom:Type', 4 => 'qty', 5 => 'code_attr', 6 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_COMPOSITE,
            'part_number_cols' => [0, 1, 2], 'part_number_sep' => ' ',
        ],
        'common_parts' => [
            'key' => 'common_parts', 'name' => 'COMMON PARTS', 'kind' => 'preset',
            'sheet' => 'COMMON PARTS', 'brand_hint' => 'COMMON PARTS',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Size', 2 => 'custom:Color',
                3 => 'custom:Brand', 4 => 'qty', 5 => 'custom:Qty Stock',
                6 => 'code_attr', 7 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_COMPOSITE,
            'part_number_cols' => [0, 1, 2], 'part_number_sep' => ' ',
        ],
        'multicab' => [
            'key' => 'multicab', 'name' => 'MULTICAB', 'kind' => 'preset',
            'sheet' => 'MULTICAB', 'brand_hint' => 'MULTICAB',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Brand', 2 => 'qty',
                3 => 'custom:Qty Stock', 4 => 'code_attr', 5 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_DESCRIPTION,
        ],
        'tools' => [
            'key' => 'tools', 'name' => 'TOOLS', 'kind' => 'preset',
            'sheet' => 'TOOLS', 'brand_hint' => 'TOOLS',
            'header_row' => 1, 'data_start_row' => 2,
            'mapping' => [
                0 => 'description', 1 => 'custom:Unit Model', 2 => 'custom:Brand',
                3 => 'qty', 4 => 'code_attr', 5 => 'price',
            ],
            'code_mode' => 'attribute', 'part_number_source' => self::PN_DESCRIPTION,
        ],
    ];

    /**
     * All templates for the UI: presets + tenant custom templates.
     *
     * @return array{presets: array<int,array>, custom: array<int,array>}
     */
    public static function all(array $ctx): array
    {
        return [
            'presets' => array_values(self::PRESETS),
            'custom'  => self::customTemplates($ctx),
        ];
    }

    /**
     * Resolve a template by key. Accepts 'preset:<key>', 'custom:<id>', or a
     * bare preset key (e.g. 'honda_gen'). Returns null when unknown.
     */
    public static function get(array $ctx, string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        if (str_starts_with($key, 'preset:')) {
            $key = substr($key, 7);
        }
        if (isset(self::PRESETS[$key])) {
            return self::PRESETS[$key];
        }
        if (str_starts_with($key, 'custom:')) {
            $id = (int)substr($key, 7);
            foreach (self::customTemplates($ctx) as $t) {
                if ((int)$t['id'] === $id) {
                    return $t;
                }
            }
        }

        return null;
    }

    /**
     * List a tenant's custom templates (newest first).
     *
     * @return array<int,array>
     */
    public static function customTemplates(array $ctx): array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $stmt = $db->query(
            'SELECT * FROM moto_import_templates
             WHERE tenant_id = :tid ORDER BY id DESC',
            [':tid' => (int)$ctx['tenant_id']]
        );
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = self::hydrate($row);
        }
        return $rows;
    }

    /**
     * Create or update a custom template. Pass 'id' to update.
     *
     * @return array the saved template (with kind=custom)
     */
    public static function saveCustom(array $ctx, array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Template name is required');
        }
        if (mb_strlen($name) > 191) {
            throw new \InvalidArgumentException('Template name is too long');
        }

        $mapping = $data['mapping'] ?? null;
        if (!is_array($mapping) || $mapping === []) {
            throw new \InvalidArgumentException('A column mapping is required');
        }
        $mapping = self::normalizeMapping($mapping);

        $codeMode = (string)($data['code_mode'] ?? 'attribute');
        if (!in_array($codeMode, ['attribute', 'decode'], true)) {
            $codeMode = 'attribute';
        }

        $pnSource = (string)($data['part_number_source'] ?? self::PN_COLUMN);
        if (!in_array($pnSource, [self::PN_COLUMN, self::PN_DESCRIPTION, self::PN_COMPOSITE], true)) {
            $pnSource = self::PN_COLUMN;
        }

        $pnCols = null;
        $pnSep = ' ';
        if ($pnSource === self::PN_COMPOSITE) {
            $cols = $data['part_number_cols'] ?? null;
            if (!is_array($cols) || $cols === []) {
                throw new \InvalidArgumentException('Composite part number needs part_number_cols');
            }
            $pnCols = array_values(array_map('intval', $cols));
            $pnSep = (string)($data['part_number_sep'] ?? ' ');
            if ($pnSep === '') {
                $pnSep = ' ';
            }
        }

        // A mapping must be able to produce a part number: a part_number
        // column, description fallback, or composite.
        if (!isset($mapping['part_number'])
            && $pnSource !== self::PN_DESCRIPTION
            && $pnSource !== self::PN_COMPOSITE) {
            throw new \InvalidArgumentException('A Part No. column is required (or choose description/composite part numbers)');
        }

        $sheet = trim((string)($data['sheet'] ?? ''));
        $sheet = $sheet !== '' ? mb_substr($sheet, 0, 191) : null;
        $headerRow = max(1, (int)($data['header_row'] ?? 1));
        $dataStartRow = max(1, (int)($data['data_start_row'] ?? 2));

        $tenantId = (int)$ctx['tenant_id'];
        $db = moto_db($tenantId);
        $id = (int)($data['id'] ?? 0);

        $jsonOpts = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($id > 0) {
            $exists = $db->query(
                'SELECT id FROM moto_import_templates WHERE tenant_id = :tid AND id = :id',
                [':tid' => $tenantId, ':id' => $id]
            )->fetchColumn();
            if ($exists === false) {
                throw new \InvalidArgumentException('Template not found');
            }
            $db->prepare(
                'UPDATE moto_import_templates
                 SET name = :name, sheet = :sheet, header_row = :hrow, data_start_row = :drow,
                     mapping = :mapping, code_mode = :cmode, part_number_source = :pnsrc,
                     part_number_cols = :pncols, part_number_sep = :pnsep, updated_at = CURRENT_TIMESTAMP
                 WHERE tenant_id = :tid AND id = :id'
            )->execute([
                ':name'   => $name,
                ':sheet'  => $sheet,
                ':hrow'   => $headerRow,
                ':drow'   => $dataStartRow,
                ':mapping'=> json_encode($mapping, $jsonOpts),
                ':cmode'  => $codeMode,
                ':pnsrc'  => $pnSource,
                ':pncols' => $pnCols !== null ? json_encode($pnCols, $jsonOpts) : null,
                ':pnsep'  => $pnSep,
                ':tid'    => $tenantId,
                ':id'     => $id,
            ]);
        } else {
            $db->prepare(
                'INSERT INTO moto_import_templates
                    (tenant_id, name, sheet, header_row, data_start_row, mapping, code_mode, part_number_source, part_number_cols, part_number_sep, created_by, created_by_name)
                 VALUES (:tid, :name, :sheet, :hrow, :drow, :mapping, :cmode, :pnsrc, :pncols, :pnsep, :uid, :actor)'
            )->execute([
                ':tid'    => $tenantId,
                ':name'   => $name,
                ':sheet'  => $sheet,
                ':hrow'   => $headerRow,
                ':drow'   => $dataStartRow,
                ':mapping'=> json_encode($mapping, $jsonOpts),
                ':cmode'  => $codeMode,
                ':pnsrc'  => $pnSource,
                ':pncols' => $pnCols !== null ? json_encode($pnCols, $jsonOpts) : null,
                ':pnsep'  => $pnSep,
                ':uid'    => (int)($ctx['user_id'] ?? 0) ?: null,
                ':actor'  => (string)($ctx['actor_name'] ?? ''),
            ]);
            $id = (int)$db->lastInsertId();
        }

        moto_audit($ctx, 'moto_inventory.import_template.saved', 'moto_import_template', (string)$id, null, [
            'name' => $name, 'part_number_source' => $pnSource, 'code_mode' => $codeMode,
        ]);

        return self::get($ctx, 'custom:' . $id) ?? ['id' => $id, 'name' => $name, 'kind' => 'custom'];
    }

    /**
     * Delete a custom template. Throws when it does not belong to the tenant.
     */
    public static function deleteCustom(array $ctx, int $id): void
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $stmt = $db->prepare(
            'DELETE FROM moto_import_templates WHERE tenant_id = :tid AND id = :id'
        );
        $stmt->execute([':tid' => (int)$ctx['tenant_id'], ':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Template not found');
        }
        moto_audit($ctx, 'moto_inventory.import_template.deleted', 'moto_import_template', (string)$id);
    }

    /**
     * Human label for a mapping field key (used by the wizard when applying a
     * template to the mapping step).
     */
    public static function fieldLabel(string $field): string
    {
        if (str_starts_with($field, 'custom:')) {
            return substr($field, 7);
        }
        return self::FIELD_LABELS[$field] ?? $field;
    }

    /**
     * Normalize a raw mapping payload to field → int column index. Accepts
     * both field→col and col→field shapes so clients can POST either.
     *
     * @param array $mapping
     * @return array<string,int>
     */
    public static function normalizeMapping(array $mapping): array
    {
        $out = [];
        foreach ($mapping as $k => $v) {
            if (is_int($k) || (is_string($k) && preg_match('/^\d+$/', $k))) {
                // col → field shape
                $col = (int)$k;
                $field = trim((string)$v);
                if ($field === '' || $col < 0 || $col > 255) {
                    continue;
                }
            } else {
                // field → col shape
                $field = trim((string)$k);
                $col = (int)$v;
                if ($col < 0 || $col > 255) {
                    continue;
                }
            }
            if (!self::isValidField($field)) {
                continue;
            }
            $out[$field] = $col;
        }
        return $out;
    }

    public static function isValidField(string $field): bool
    {
        if (isset(self::FIELD_LABELS[$field])) {
            return true;
        }
        if (str_starts_with($field, 'custom:')) {
            $label = substr($field, 7);
            return $label !== '' && mb_strlen($label) <= 60;
        }
        return false;
    }

    private static function hydrate(array $row): array
    {
        $mapping = json_decode((string)$row['mapping'], true);
        $mapping = is_array($mapping) ? $mapping : [];
        // Stored shape is field → col (matches ImportService::stage mapping).
        // API output is col → field (matches the bundled presets and what the
        // wizard's mapping step consumes).
        $colToField = [];
        foreach ($mapping as $field => $col) {
            $field = (string)$field;
            if (self::isValidField($field)) {
                $colToField[(int)$col] = $field;
            }
        }
        ksort($colToField);

        $pnCols = $row['part_number_cols'] !== null ? json_decode((string)$row['part_number_cols'], true) : null;
        $pnCols = is_array($pnCols) ? array_map('intval', $pnCols) : null;

        return [
            'id'                 => (int)$row['id'],
            'key'                => 'custom:' . (int)$row['id'],
            'name'               => (string)$row['name'],
            'kind'               => 'custom',
            'sheet'              => $row['sheet'] !== null ? (string)$row['sheet'] : '',
            'header_row'         => (int)$row['header_row'],
            'data_start_row'     => (int)$row['data_start_row'],
            'mapping'            => $colToField,
            'code_mode'          => (string)$row['code_mode'],
            'part_number_source' => (string)$row['part_number_source'],
            'part_number_cols'   => $pnCols,
            'part_number_sep'    => (string)$row['part_number_sep'],
            'created_by_name'    => (string)$row['created_by_name'],
            'created_at'         => (string)$row['created_at'],
        ];
    }
}
