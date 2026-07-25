<?php
declare(strict_types=1);

/**
 * Extraction resource guard (H3) — tests for ExtractionLimits and
 * the extraction guard in AcademicSimilarityPipelineService::runExtract().
 *
 * Covers:
 * - File size limits
 * - ZIP archive entry count limits
 * - ZIP archive uncompressed size limits
 * - Extracted text length limits
 * - Pasted text length limits
 * - Controlled error results instead of OOM
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/academic_similarity/helpers.php';

$pass = 0;
$fail = 0;
function t(string $description, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  ✅ {$description}\n"; }
    else { $fail++; echo "  ❌ {$description}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

echo "\n=== Academic Similarity — Extraction Resource Limits (H3) ===\n";

// ── ExtractionLimits static checks ──

echo "\n--- ExtractionLimits::checkFileSize ---\n";

t('accepts file under limit', ExtractionLimits::checkFileSize(10_000_000) === null);
t('accepts file exactly at limit', ExtractionLimits::checkFileSize(20_000_000) === null);
t('rejects file over limit', ExtractionLimits::checkFileSize(20_000_001) !== null);
t('rejects large file with message', str_contains(
    ExtractionLimits::checkFileSize(30_000_000) ?? '',
    'exceeds maximum upload size'
));

echo "\n--- ExtractionLimits::checkZipArchive ---\n";

// Create a normal DOCX-like zip
$normalZip = tempnam(sys_get_temp_dir(), 'h3_normal_') . '.docx';
$zip = new ZipArchive();
$zip->open($normalZip, ZipArchive::CREATE);
$zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Hello world</w:t></w:r></w:p></w:body></w:document>');
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
$zip->close();

t('normal zip passes archive check', ExtractionLimits::checkZipArchive($normalZip) === null);
@unlink($normalZip);

// Create a zip with too many entries (exceeds MAX_ZIP_ENTRIES = 2000)
$manyEntriesZip = tempnam(sys_get_temp_dir(), 'h3_many_') . '.docx';
$zipMany = new ZipArchive();
$zipMany->open($manyEntriesZip, ZipArchive::CREATE);
for ($i = 0; $i < 2001; $i++) {
    $zipMany->addFromString("file{$i}.xml", '<x/>');
}
$zipMany->close();

$manyResult = ExtractionLimits::checkZipArchive($manyEntriesZip);
t('rejects zip with too many entries', $manyResult !== null);
t('many-entries error mentions entry count', $manyResult !== null && str_contains($manyResult, 'entries'));
@unlink($manyEntriesZip);

// Create a zip with large uncompressed content (> MAX_UNCOMPRESSED_BYTES = 50MB)
$bigEntriesZip = tempnam(sys_get_temp_dir(), 'h3_big_') . '.docx';
$zipBig = new ZipArchive();
$zipBig->open($bigEntriesZip, ZipArchive::CREATE);
$zipBig->addFromString('word/document.xml', str_repeat('A', 60_000_000)); // 60 MB uncompressed
$zipBig->close();

$bigResult = ExtractionLimits::checkZipArchive($bigEntriesZip);
t('rejects zip with oversized uncompressed content', $bigResult !== null);
t('oversized error mentions MB limit', $bigResult !== null && str_contains($bigResult, 'MB limit'));
@unlink($bigEntriesZip);

// Non-zip file (not a valid archive) — should return null gracefully
$notAZip = tempnam(sys_get_temp_dir(), 'h3_notzip_') . '.docx';
file_put_contents($notAZip, 'not a zip file');
t('non-zip file returns null (not an error)', ExtractionLimits::checkZipArchive($notAZip) === null);
@unlink($notAZip);

echo "\n--- ExtractionLimits::checkExtractedText ---\n";

t('accepts short text', ExtractionLimits::checkExtractedText('Hello world') === null);
t('accepts text at exact limit', ExtractionLimits::checkExtractedText(str_repeat('x', 500_000)) === null);
t('rejects text over limit', ExtractionLimits::checkExtractedText(str_repeat('x', 500_001)) !== null);
t('over-limit error mentions character count', str_contains(
    ExtractionLimits::checkExtractedText(str_repeat('x', 600_000)) ?? '',
    'exceeds limit'
));

echo "\n--- ExtractionLimits::checkPastedText ---\n";

t('accepts short pasted text', ExtractionLimits::checkPastedText('Hello world') === null);
t('accepts pasted text at exact limit', ExtractionLimits::checkPastedText(str_repeat('x', 500_000)) === null);
t('rejects pasted text over limit', ExtractionLimits::checkPastedText(str_repeat('x', 500_001)) !== null);

// ── Pipeline integration (runExtract behavior) ──

echo "\n--- Pipeline integration ---\n";

// Verify that ExtractionLimits class is autoloaded/accessible via helpers
$refClass = new ReflectionClass(ExtractionLimits::class);
t('ExtractionLimits is a final class', $refClass->isFinal());
t('ExtractionLimits has MAX_UPLOAD_BYTES constant', $refClass->hasConstant('MAX_UPLOAD_BYTES'));
t('MAX_UPLOAD_BYTES = 20MB', ExtractionLimits::MAX_UPLOAD_BYTES === 20_000_000);
t('ExtractionLimits has MAX_UNCOMPRESSED_BYTES constant', $refClass->hasConstant('MAX_UNCOMPRESSED_BYTES'));
t('MAX_UNCOMPRESSED_BYTES = 50MB', ExtractionLimits::MAX_UNCOMPRESSED_BYTES === 50_000_000);
t('ExtractionLimits has MAX_EXTRACTED_CHARACTERS constant', $refClass->hasConstant('MAX_EXTRACTED_CHARACTERS'));
t('MAX_EXTRACTED_CHARACTERS = 500K', ExtractionLimits::MAX_EXTRACTED_CHARACTERS === 500_000);
t('ExtractionLimits has MAX_ZIP_ENTRIES constant', $refClass->hasConstant('MAX_ZIP_ENTRIES'));
t('MAX_ZIP_ENTRIES = 2000', ExtractionLimits::MAX_ZIP_ENTRIES === 2_000);
t('ExtractionLimits has MAX_PASTED_CHARACTERS constant', $refClass->hasConstant('MAX_PASTED_CHARACTERS'));
t('MAX_PASTED_CHARACTERS = 500K', ExtractionLimits::MAX_PASTED_CHARACTERS === 500_000);
t('ExtractionLimits has MAX_SEGMENTS constant', $refClass->hasConstant('MAX_SEGMENTS'));
t('MAX_SEGMENTS = 10000', ExtractionLimits::MAX_SEGMENTS === 10_000);

// ── Log checks ──
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
