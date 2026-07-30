<?php

/**
 * Bare layout for printable documents.
 *
 * No topbar, no sidebar, no Bootstrap: none of it belongs on paper, and the
 * print stylesheet is small enough to be worth loading on its own.
 *
 * Devanagari is rendered by the browser, which is the point - see
 * VisitController::print() for why this is not a generated PDF.
 *
 * @var string $content
 * @var string $pageTitle
 */

use App\Core\View;

?><!doctype html>
<html lang="hi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($pageTitle ?? 'Print') ?></title>
    <link rel="stylesheet" href="<?= View::e(View::asset('css/print-visit.css')) ?>">
</head>
<body>
    <!-- Hidden when printing. Gives the operator the one button they need. -->
    <div class="no-print toolbar">
        <button type="button" onclick="window.print()" class="btn-print">
            प्रिंट / PDF सेव करें
        </button>
        <a href="<?= View::e(View::url('visits')) ?>" class="btn-back">वापस जाएँ</a>
        <span class="hint">
            प्रिंट डायलॉग में <strong>Destination</strong> को <strong>Save as PDF</strong>
            चुनें। पेपर साइज़ <strong>A4</strong> रखें।
        </span>
    </div>

    <?= $content ?>
</body>
</html>
