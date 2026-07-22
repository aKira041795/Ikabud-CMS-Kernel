<?php
declare(strict_types=1);

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

echo "\n=== Academic Similarity — Security Validators ===\n";

$validator = new AcademicSimilarityFileValidator();
$settings = ['max_file_size_mb' => 10];

// ── File Extension Validation ──

t('validates .docx extension', $validator->validateExtension('report.docx', 'docx,pdf,txt'));
t('validates .pdf extension', $validator->validateExtension('paper.pdf', 'docx,pdf,txt'));
t('validates .txt extension', $validator->validateExtension('notes.txt', 'docx,pdf,txt'));
t('rejects .exe extension', !$validator->validateExtension('virus.exe', 'docx,pdf,txt'));
t('rejects .php extension', !$validator->validateExtension('shell.php', 'docx,pdf,txt'));
t('rejects .html extension', !$validator->validateExtension('page.html', 'docx,pdf,txt'));
t('rejects no extension', !$validator->validateExtension('Makefile', 'docx,pdf,txt'));
t('case insensitive validation', $validator->validateExtension('REPORT.DOCX', 'docx,pdf,txt'));
t('double extension returns the last segment (pdf is allowed)', $validator->validateExtension('report.docx.pdf', 'docx,pdf,txt'));

// ── MIME Type Detection ──

t('validates text/plain mime', $validator->validateMimeType('text/plain'));
t('validates application/pdf mime', $validator->validateMimeType('application/pdf'));
t('validates docx mime', $validator->validateMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
t('validates text/plain with charset', $validator->validateMimeType('text/plain; charset=utf-8'));
t('rejects application/exe mime', !$validator->validateMimeType('application/x-msdownload'));
t('rejects image/jpeg mime', !$validator->validateMimeType('image/jpeg'));

// ── File Size Validation ──

$maxBytes = 10 * 1024 * 1024; // 10 MB
t('accepts file under limit', $validator->validateFileSize(5 * 1024 * 1024, 10));
t('accepts file exactly at limit', $validator->validateFileSize(10 * 1024 * 1024, 10));
t('rejects file over limit', !$validator->validateFileSize(11 * 1024 * 1024, 10));
t('rejects file significantly over limit', !$validator->validateFileSize(100 * 1024 * 1024, 10));
t('accepts zero-byte file (edge)', $validator->validateFileSize(0, 10));

// ── Content Safety: DOCX validation using temp files ──

// Create empty zip (simulates invalid DOCX)
$emptyZip = tempnam(sys_get_temp_dir(), 'ac_test_empty_') . '.docx';
$zip = new ZipArchive();
$zip->open($emptyZip, ZipArchive::CREATE);
$zip->addFromString('empty.txt', '');
$zip->close();
t('empty zip DOCX passes content validation', $validator->validateContent($emptyZip));
@unlink($emptyZip);

// Create oversized zip (simulates zip bomb)
$bigZip = tempnam(sys_get_temp_dir(), 'ac_test_big_') . '.docx';
$bigZipHandle = new ZipArchive();
$bigZipHandle->open($bigZip, ZipArchive::CREATE);
// Add dummy content that reports large uncompressed size
$bigZipHandle->addFromString('large.xml', str_repeat('A', 200 * 1024 * 1024)); // 200 MB uncompressed
$bigZipHandle->setCompressionName('large.xml', ZipArchive::CM_STORE);
$bigZipHandle->close();
t('oversized DOCX content fails validation', !$validator->validateContent($bigZip));
@unlink($bigZip);

// Invalid zip (not a real zip file)
$notAZip = tempnam(sys_get_temp_dir(), 'ac_test_notzip_') . '.docx';
file_put_contents($notAZip, 'not a zip file');
t('invalid zip DOCX fails content validation', !$validator->validateContent($notAZip));
@unlink($notAZip);

// ── Content Safety: PDF encryption detection ──

// Create temp PDF with encryption marker
$encryptedPdf = tempnam(sys_get_temp_dir(), 'ac_test_enc_') . '.pdf';
file_put_contents($encryptedPdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n4 0 obj\n<< /Length 44 >>\nstream\nBT /F1 12 Tf 100 700 Td (Hello) Tj ET\nendstream\nendobj\n5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\nxref\n...\n/Encrypt /Standard\n...\ntrailer\n<< /Size 6 /Root 1 0 R /Encrypt 6 0 R >>\n%%EOF");
t('encrypted PDF fails content validation', !$validator->validateContent($encryptedPdf));
@unlink($encryptedPdf);

// Clean PDF (no encryption)
$cleanPdf = tempnam(sys_get_temp_dir(), 'ac_test_clean_') . '.pdf';
file_put_contents($cleanPdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\ntrailer\n<< /Size 2 /Root 1 0 R >>\n%%EOF");
t('clean PDF passes content validation', $validator->validateContent($cleanPdf));
@unlink($cleanPdf);

// ── Tenant Isolation (conceptual) ──

// Simulate the tenant policy assertion
$tenantPolicyRef = new ReflectionClass(AcademicSimilarityTenantPolicy::class);
t('TenantPolicy has assertTenantScope method', $tenantPolicyRef->hasMethod('assertTenantScope'));
t('TenantPolicy has assertResourceOwnership method', $tenantPolicyRef->hasMethod('assertResourceOwnership'));
t('TenantPolicy has assertRole method', $tenantPolicyRef->hasMethod('assertRole'));

// Test the assertion logic inline
$thrown = false;
try {
    AcademicSimilarityTenantPolicy::assertTenantScope('tenant-a', 'tenant-b');
} catch (\RuntimeException $e) {
    $thrown = true;
    t('tenant mismatch throws exception', str_contains($e->getMessage(), 'Tenant scope mismatch'));
}
t('assertTenantScope throws on mismatch', $thrown);

$noThrow = false;
try {
    AcademicSimilarityTenantPolicy::assertTenantScope('same-tenant', 'same-tenant');
    $noThrow = true;
} catch (\RuntimeException $e) {
    $noThrow = false;
}
t('assertTenantScope passes on match', $noThrow);

// ── Path traversal in filenames ──

t('rejects path traversal in extension check', !$validator->validateExtension('../../../etc/passwd', 'docx,pdf,txt'));
t('path traversal .pdf gets extension pdf (allowed)', $validator->validateExtension('../../config.php.pdf', 'docx,pdf,txt'));

// Check logs
$appLog = file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errorLog = file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('app.log has no critical entries', !str_contains($appLog, '[critical]'), trim($appLog));
t('error.log is empty', trim($errorLog) === '', trim($errorLog));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
