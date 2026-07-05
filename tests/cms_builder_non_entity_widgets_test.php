<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/cms/builder-preview';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CMS BUILDER NON-ENTITY WIDGETS ===\n";

$validation = cmsBuilderValidateDocument([
    'schema_version' => '1.0',
    'document' => [
        'id' => 'doc_root',
        'type' => 'document',
        'props' => [],
        'style' => [],
        'children' => [
            [
                'id' => 'section-test',
                'type' => 'section',
                'props' => [],
                'style' => [],
                'children' => [
                    [
                        'id' => 'pricing-test',
                        'type' => 'pricing_table',
                        'props' => [
                            'planName' => 'Growth',
                            'features' => [['text' => 'Priority support', 'included' => true]],
                            'highlighted' => true,
                            'ribbon' => 'Best Value',
                        ],
                        'style' => [],
                        'children' => [],
                        'meta' => [],
                    ],
                    [
                        'id' => 'countdown-test',
                        'type' => 'countdown',
                        'props' => [
                            'targetDate' => date('c', time() + 3600),
                            'showHours' => false,
                            'showSeconds' => false,
                            'labels' => ['days' => 'Days Left', 'minutes' => 'Minutes Left'],
                        ],
                        'style' => [],
                        'children' => [],
                        'meta' => [],
                    ],
                    [
                        'id' => 'cta-test',
                        'type' => 'call_to_action',
                        'props' => [
                            'title' => 'Ship Faster',
                            'description' => 'Launch your next release with a cleaner builder workflow.',
                            'buttonText' => 'Start Now',
                            'buttonUrl' => '/start',
                            'secondaryButtonText' => 'See Plans',
                            'secondaryButtonUrl' => '/plans',
                        ],
                        'style' => [],
                        'children' => [],
                        'meta' => [],
                    ],
                    [
                        'id' => 'map-test',
                        'type' => 'map',
                        'props' => [
                            'mapType' => 'embed',
                            'embedUrl' => 'https://maps.example.test/embed/location',
                        ],
                        'style' => [],
                        'children' => [],
                        'meta' => [],
                    ],
                    [
                        'id' => 'search-box-test',
                        'type' => 'search_box',
                        'props' => [
                            'placeholder' => 'Search the site',
                        ],
                        'style' => [],
                        'children' => [],
                        'meta' => [],
                    ],
                    [
                        'id' => 'badge-test',
                        'type' => 'badge',
                        'props' => [
                            'text' => 'Featured',
                            'variant' => 'success',
                        ],
                        'style' => [],
                        'children' => [],
                        'meta' => [],
                    ],
                    [
                        'id' => 'stat-card-test',
                        'type' => 'stat_card',
                        'props' => [
                            'value' => '256',
                            'label' => 'Open Seats',
                            'description' => 'Available for the next cohort.',
                        ],
                        'style' => [],
                        'children' => [],
                        'meta' => [],
                    ],
                    [
                        'id' => 'contact-card-test',
                        'type' => 'contact_card',
                        'props' => [
                            'title' => 'Talk to Admissions',
                            'phone' => '+63 917 555 0100',
                            'email' => 'admissions@example.test',
                            'address' => 'Makati City',
                            'buttonText' => 'Request Info',
                            'buttonUrl' => '/cms/contact',
                        ],
                        'style' => [],
                        'children' => [],
                        'meta' => [],
                    ],
                    [
                        'id' => 'flip-box-test',
                        'type' => 'flip_box',
                        'props' => [
                            'frontTitle' => 'Front Side',
                            'frontDescription' => 'Hover for more details',
                            'backTitle' => 'Back Side',
                            'backDescription' => 'Secondary details live here.',
                        ],
                        'style' => [],
                        'children' => [],
                        'meta' => [],
                    ],
                ],
                'meta' => [],
            ],
        ],
        'meta' => [],
    ],
]);

t('builder validation accepts reviewed non-entity widgets', !empty($validation['ok']), json_encode($validation['issues'] ?? []));

$pricingHtml = cmsBuilderRenderNode([
    'id' => 'pricing-test',
    'type' => 'pricing_table',
    'props' => [
        'planName' => 'Growth',
        'price' => '79',
        'currency' => '$',
        'period' => '/month',
        'features' => [['text' => 'Priority support', 'included' => true]],
        'highlighted' => true,
        'ribbon' => 'Best Value',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

$countdownHtml = cmsBuilderRenderNode([
    'id' => 'countdown-test',
    'type' => 'countdown',
    'props' => [
        'targetDate' => date('c', time() + 3600),
        'showHours' => false,
        'showSeconds' => false,
        'labels' => [
            'days' => 'Days Left',
            'hours' => 'Hours Left',
            'minutes' => 'Minutes Left',
            'seconds' => 'Seconds Left',
        ],
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

$ctaHtml = cmsBuilderRenderNode([
    'id' => 'cta-test',
    'type' => 'call_to_action',
    'props' => [
        'title' => 'Ship Faster',
        'description' => 'Launch your next release with a cleaner builder workflow.',
        'buttonText' => 'Start Now',
        'buttonUrl' => '/start',
        'secondaryButtonText' => 'See Plans',
        'secondaryButtonUrl' => '/plans',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

$mapHtml = cmsBuilderRenderNode([
    'id' => 'map-test',
    'type' => 'map',
    'props' => [
        'mapType' => 'embed',
        'embedUrl' => 'https://maps.example.test/embed/location',
        'height' => '360px',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

$flipBoxHtml = cmsBuilderRenderNode([
    'id' => 'flip-box-test',
    'type' => 'flip_box',
    'props' => [
        'frontTitle' => 'Front Side',
        'frontDescription' => 'Hover for more details',
        'backTitle' => 'Back Side',
        'backDescription' => 'Secondary details live here.',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

$searchBoxHtml = cmsBuilderRenderNode([
    'id' => 'search-box-test',
    'type' => 'search_box',
    'props' => [
        'placeholder' => 'Search the site',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

$badgeHtml = cmsBuilderRenderNode([
    'id' => 'badge-test',
    'type' => 'badge',
    'props' => [
        'text' => 'Featured',
        'variant' => 'success',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

$statCardHtml = cmsBuilderRenderNode([
    'id' => 'stat-card-test',
    'type' => 'stat_card',
    'props' => [
        'value' => '256',
        'label' => 'Open Seats',
        'description' => 'Available for the next cohort.',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

$contactCardHtml = cmsBuilderRenderNode([
    'id' => 'contact-card-test',
    'type' => 'contact_card',
    'props' => [
        'title' => 'Talk to Admissions',
        'phone' => '+63 917 555 0100',
        'email' => 'admissions@example.test',
        'address' => 'Makati City',
        'buttonText' => 'Request Info',
        'buttonUrl' => '/cms/contact',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

t('pricing table renderer outputs configured ribbon', str_contains($pricingHtml, 'Best Value'), $pricingHtml);
t('countdown renderer hides disabled hour and second labels', !str_contains($countdownHtml, 'Hours Left') && !str_contains($countdownHtml, 'Seconds Left'), $countdownHtml);
t('countdown renderer keeps enabled unit labels', str_contains($countdownHtml, 'Days Left') && str_contains($countdownHtml, 'Minutes Left'), $countdownHtml);
t('CTA renderer outputs both primary and secondary buttons', str_contains($ctaHtml, 'Start Now') && str_contains($ctaHtml, 'See Plans') && str_contains($ctaHtml, '/plans'), $ctaHtml);
t('map renderer outputs configured embed iframe', str_contains($mapHtml, 'https://maps.example.test/embed/location') && str_contains($mapHtml, '<iframe'), $mapHtml);
t('flip box renderer outputs front description without errors', str_contains($flipBoxHtml, 'Hover for more details') && str_contains($flipBoxHtml, 'Back Side'), $flipBoxHtml);
t('search box renderer defaults to the CMS search endpoint', str_contains($searchBoxHtml, 'action="/cms/search"') && str_contains($searchBoxHtml, 'name="q"'), $searchBoxHtml);
t('badge renderer outputs the configured label', str_contains($badgeHtml, 'Featured'), $badgeHtml);
t('stat card renderer outputs value and label', str_contains($statCardHtml, '256') && str_contains($statCardHtml, 'Open Seats'), $statCardHtml);
t('contact card renderer outputs contact details and CTA', str_contains($contactCardHtml, 'admissions@example.test') && str_contains($contactCardHtml, 'Request Info') && str_contains($contactCardHtml, 'Makati City'), $contactCardHtml);

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP errors in error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

exit(0);