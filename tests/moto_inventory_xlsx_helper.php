<?php

declare(strict_types=1);

/**
 * Moto Inventory — test support: minimal XLSX builder.
 *
 * Builds a real, parseable .xlsx (ZIP + XML) matching what the module's
 * ImportService::parseWorkbook() reads, so import behavior is exercised
 * against genuine files rather than mocks.
 */

/**
 * Build a minimal single-sheet .xlsx file into $path.
 *
 * @param array<int, array<int, string>> $rows 2D grid (row → col → value)
 */
function moto_test_build_xlsx(string $path, array $rows): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create xlsx');
    }

    // Shared strings: unique non-numeric cells.
    $shared = [];
    $sharedIndex = [];
    foreach ($rows as $row) {
        foreach ($row as $cell) {
            if ($cell !== '' && !is_numeric($cell)) {
                $key = $cell;
                if (!isset($sharedIndex[$key])) {
                    $sharedIndex[$key] = count($shared);
                    $shared[] = $cell;
                }
            }
        }
    }

    $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
    foreach ($shared as $s) {
        $sharedXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
    }
    $sharedXml .= '</sst>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $rowIdx => $row) {
        $sheetXml .= '<row r="' . ($rowIdx + 1) . '">';
        foreach ($row as $colIdx => $value) {
            $ref = moto_test_col_letter($colIdx) . ($rowIdx + 1);
            if ($value === '') {
                continue;
            }
            if (is_numeric($value)) {
                $sheetXml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
            } else {
                $idx = $sharedIndex[$value];
                $sheetXml .= '<c r="' . $ref . '" t="s"><v>' . $idx . '</v></c>';
            }
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Parts" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>' .
        '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' .
        '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>';

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $relsXml);
    $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();
}

function moto_test_col_letter(int $index): string
{
    $letter = '';
    $index++;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $index = intdiv($index - 1, 26);
    }
    return $letter;
}
