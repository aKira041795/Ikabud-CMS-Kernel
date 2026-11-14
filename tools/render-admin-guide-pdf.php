<?php
declare(strict_types=1);
/**
 * Render the admin guide HTML to PDF with dompdf.
 *
 * Kept in the repo (not a throwaway) because the guide is a living document: edit the .html and
 * re-run this to regenerate. Idempotent — it only writes the .pdf next to the source.
 *
 *   php tools/render-admin-guide-pdf.php
 */
require __DIR__ . '/../vendor/autoload.php';

$docsDir = __DIR__ . '/../docs/daily-ledger';
$htmlFile = $docsDir . '/admin-guide-2026-11-14.html';
$pdfFile = $docsDir . '/admin-guide-2026-11-14.pdf';

if (!is_file($htmlFile)) {
    fwrite(STDERR, "missing source: {$htmlFile}\n");
    exit(1);
}

$options = new \Dompdf\Options();
// No remote fetches: the document is self-contained by design, and a printer should never reach
// out to the network to lay out a page.
$options->setIsRemoteEnabled(false);
$options->setIsHtml5ParserEnabled(true);
$options->setDefaultFont('DejaVu Sans');

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml((string)file_get_contents($htmlFile), 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$bytes = (string)$dompdf->output();
if ($bytes === '') {
    fwrite(STDERR, "dompdf produced no output\n");
    exit(1);
}
file_put_contents($pdfFile, $bytes);
printf("wrote %s (%d bytes)\n", $pdfFile, strlen($bytes));
