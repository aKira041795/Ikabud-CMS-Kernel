<?php

declare(strict_types=1);

function cmsRenderBlocks(?string $blocksJson): string
{
    if ($blocksJson === null || trim($blocksJson) === '') {
        return '';
    }
    $blocks = json_decode($blocksJson, true);
    if (!is_array($blocks)) {
        return '';
    }

    $out = '';
    foreach ($blocks as $b) {
        if (!is_array($b)) {
            continue;
        }
        $type = trim((string)($b['type'] ?? ''));
        $blockStyles = $b['styles'] ?? null;
        $wrapStyle = _cmsBlockInlineStyle($blockStyles);
        $wrapClass = is_array($blockStyles) ? trim((string)($blockStyles['cssClass'] ?? '')) : '';

        if ($type === 'paragraph') {
            $text = (string)($b['text'] ?? '');
            $attr = _cmsBlockAttr('cms-block-paragraph', $wrapClass, $wrapStyle);
            $out .= '<p' . $attr . '>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';
        } elseif ($type === 'heading') {
            $text = (string)($b['text'] ?? '');
            $level = (int)($b['level'] ?? 2);
            if ($level < 2 || $level > 4) {
                $level = 2;
            }
            $attr = _cmsBlockAttr('', $wrapClass, $wrapStyle);
            $out .= '<h' . $level . $attr . '>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</h' . $level . '>';
        } elseif ($type === 'image') {
            $url = trim((string)($b['url'] ?? ''));
            if ($url !== '') {
                $alt = (string)($b['alt'] ?? '');
                $caption = (string)($b['caption'] ?? '');
                $attr = _cmsBlockAttr('', $wrapClass, $wrapStyle);
                $out .= '<figure' . $attr . '>';
                $out .= '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">';
                if (trim($caption) !== '') {
                    $out .= '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</figcaption>';
                }
                $out .= '</figure>';
            }
        } elseif ($type === 'html') {
            $html = (string)($b['html'] ?? '');
            if ($wrapStyle !== '' || $wrapClass !== '') {
                $attr = _cmsBlockAttr('', $wrapClass, $wrapStyle);
                $out .= '<div' . $attr . '>' . $html . '</div>';
            } else {
                $out .= $html;
            }
        } elseif ($type === 'list') {
            $style = trim((string)($b['style'] ?? 'ul'));
            $items = $b['items'] ?? [];
            if (!is_array($items)) {
                $items = [];
            }
            $tag = $style === 'ol' ? 'ol' : 'ul';
            $attr = _cmsBlockAttr('', $wrapClass, $wrapStyle);
            $out .= '<' . $tag . $attr . '>';
            foreach ($items as $it) {
                $out .= '<li>' . htmlspecialchars((string)$it, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $out .= '</' . $tag . '>';
        } elseif ($type === 'quote') {
            $text = (string)($b['text'] ?? '');
            $citation = (string)($b['citation'] ?? '');
            $attr = _cmsBlockAttr('', $wrapClass, $wrapStyle);
            $out .= '<blockquote' . $attr . '>';
            $out .= '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';
            if (trim($citation) !== '') {
                $out .= '<cite>' . htmlspecialchars($citation, ENT_QUOTES, 'UTF-8') . '</cite>';
            }
            $out .= '</blockquote>';
        } elseif ($type === 'button') {
            $text = (string)($b['text'] ?? 'Click here');
            $url = trim((string)($b['url'] ?? '#'));
            $style = trim((string)($b['style'] ?? 'primary'));
            $target = trim((string)($b['target'] ?? '_self'));
            $cls = $style === 'outline' ? 'cms-btn cms-btn-outline' : 'cms-btn cms-btn-primary';
            $attr = _cmsBlockAttr('cms-btn-wrap', $wrapClass, $wrapStyle);
            $out .= '<div' . $attr . '>';
            $out .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="' . $cls . '"';
            if ($target === '_blank') {
                $out .= ' target="_blank" rel="noopener noreferrer"';
            }
            $out .= '>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
            $out .= '</div>';
        } elseif ($type === 'gallery') {
            $mediaIds = $b['media_ids'] ?? [];
            $urls = $b['urls'] ?? [];
            $columns = max(1, min(6, (int)($b['columns'] ?? 3)));
            if (!is_array($urls)) $urls = [];
            if (!is_array($mediaIds)) $mediaIds = [];
            $images = !empty($urls) ? $urls : $mediaIds;
            if (!empty($images)) {
                $attr = _cmsBlockAttr('cms-gallery cms-gallery-cols-' . $columns, $wrapClass, $wrapStyle);
                $out .= '<div' . $attr . '>';
                foreach ($images as $img) {
                    $src = is_array($img) ? (string)($img['url'] ?? '') : (string)$img;
                    $alt = is_array($img) ? (string)($img['alt'] ?? '') : '';
                    if ($src !== '') {
                        $out .= '<figure class="cms-gallery-item">';
                        $out .= '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" loading="lazy">';
                        $out .= '</figure>';
                    }
                }
                $out .= '</div>';
            }
        } elseif ($type === 'section') {
            $bgColor = trim((string)($b['bg_color'] ?? ''));
            $textColor = trim((string)($b['text_color'] ?? ''));
            $bgImage = trim((string)($b['bg_image'] ?? ''));
            $padding = max(0, (int)($b['padding'] ?? 48));
            $maxWidth = trim((string)($b['max_width'] ?? '1140px'));
            $textAlign = trim((string)($b['text_align'] ?? ''));
            $content = (string)($b['content'] ?? '');

            $sectionStyle = 'padding:' . $padding . 'px 0;';
            if ($bgColor !== '') $sectionStyle .= 'background-color:' . htmlspecialchars($bgColor, ENT_QUOTES, 'UTF-8') . ';';
            if ($textColor !== '') $sectionStyle .= 'color:' . htmlspecialchars($textColor, ENT_QUOTES, 'UTF-8') . ';';
            if ($bgImage !== '') $sectionStyle .= 'background-image:url(' . htmlspecialchars($bgImage, ENT_QUOTES, 'UTF-8') . ');background-size:cover;background-position:center;';
            if ($textAlign !== '') $sectionStyle .= 'text-align:' . htmlspecialchars($textAlign, ENT_QUOTES, 'UTF-8') . ';';

            $innerStyle = $maxWidth !== '' ? 'max-width:' . htmlspecialchars($maxWidth, ENT_QUOTES, 'UTF-8') . ';margin:0 auto;padding:0 20px;' : 'padding:0 20px;';

            $out .= '<section class="cms-section' . ($wrapClass !== '' ? ' ' . htmlspecialchars($wrapClass, ENT_QUOTES, 'UTF-8') : '') . '" style="' . $sectionStyle . '">';
            $out .= '<div class="cms-section-inner" style="' . $innerStyle . '">';
            $out .= $content;
            $out .= '</div></section>';
        } elseif ($type === 'columns') {
            $cols = $b['columns'] ?? [];
            $layout = trim((string)($b['layout'] ?? '50/50'));
            $gap = trim((string)($b['gap'] ?? '24px'));
            $valign = trim((string)($b['valign'] ?? 'start'));
            $stackMobile = (bool)($b['stack_mobile'] ?? true);

            if (is_array($cols) && !empty($cols)) {
                $gridMap = [
                    '50/50' => '1fr 1fr', '33/33/33' => '1fr 1fr 1fr',
                    '70/30' => '7fr 3fr', '30/70' => '3fr 7fr',
                    '25/50/25' => '1fr 2fr 1fr', '25/25/25/25' => '1fr 1fr 1fr 1fr',
                ];
                $gridCols = $gridMap[$layout] ?? str_repeat('1fr ', count($cols));
                $gridStyle = 'display:grid;grid-template-columns:' . trim($gridCols) . ';gap:' . htmlspecialchars($gap, ENT_QUOTES, 'UTF-8') . ';align-items:' . htmlspecialchars($valign, ENT_QUOTES, 'UTF-8') . ';';

                $mobileCls = $stackMobile ? ' cms-columns-stack' : '';
                $attr = _cmsBlockAttr('cms-columns' . $mobileCls, $wrapClass, $wrapStyle . $gridStyle);
                $out .= '<div' . $attr . '>';
                foreach ($cols as $col) {
                    $colContent = (string)($col['content'] ?? '');
                    $innerBlocks = $col['blocks'] ?? [];
                    $out .= '<div class="cms-column">';
                    if (is_array($innerBlocks) && !empty($innerBlocks)) {
                        $out .= cmsRenderBlocks(json_encode($innerBlocks));
                    } elseif ($colContent !== '') {
                        $out .= $colContent;
                    }
                    $out .= '</div>';
                }
                $out .= '</div>';
            }
        } elseif ($type === 'embed') {
            $url = trim((string)($b['url'] ?? ''));
            $embedHtml = (string)($b['html'] ?? '');
            if ($embedHtml !== '') {
                $out .= '<div class="cms-embed">' . $embedHtml . '</div>';
            } elseif ($url !== '') {
                $provider = cmsDetectEmbedProvider($url);
                $embedCode = cmsGenerateEmbedHtml($url, $provider);
                $out .= '<div class="cms-embed">' . $embedCode . '</div>';
            }
        } elseif ($type === 'spacer') {
            $height = max(8, min(200, (int)($b['height'] ?? 40)));
            $out .= '<div class="cms-spacer" style="height:' . $height . 'px"></div>';
        } elseif ($type === 'separator') {
            $style = trim((string)($b['style'] ?? 'solid'));
            $cls = in_array($style, ['dashed', 'dotted', 'double'], true) ? 'cms-separator cms-separator-' . $style : 'cms-separator';
            $out .= '<hr class="' . $cls . '">';
        }
    }

    return $out;
}

/**
 * Build inline style string from block styles array.
 */

function _cmsBlockInlineStyle(?array $styles): string
{
    if (!is_array($styles) || empty($styles)) return '';
    $css = '';
    if (!empty($styles['backgroundColor'])) $css .= 'background-color:' . htmlspecialchars($styles['backgroundColor'], ENT_QUOTES, 'UTF-8') . ';';
    if (!empty($styles['color'])) $css .= 'color:' . htmlspecialchars($styles['color'], ENT_QUOTES, 'UTF-8') . ';';
    if (!empty($styles['padding'])) $css .= 'padding:' . htmlspecialchars($styles['padding'], ENT_QUOTES, 'UTF-8') . ';';
    if (!empty($styles['margin'])) $css .= 'margin:' . htmlspecialchars($styles['margin'], ENT_QUOTES, 'UTF-8') . ';';
    if (!empty($styles['borderRadius'])) $css .= 'border-radius:' . htmlspecialchars($styles['borderRadius'], ENT_QUOTES, 'UTF-8') . ';';
    return $css;
}

/**
 * Build combined class + style attribute string for a block wrapper.
 */

function _cmsBlockAttr(string $baseClass = '', string $extraClass = '', string $inlineStyle = ''): string
{
    $cls = trim($baseClass . ($extraClass !== '' ? ' ' . $extraClass : ''));
    $attr = '';
    if ($cls !== '') $attr .= ' class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '"';
    if ($inlineStyle !== '') $attr .= ' style="' . htmlspecialchars($inlineStyle, ENT_QUOTES, 'UTF-8') . '"';
    return $attr;
}

/**
 * Detect embed provider from a URL.
 */

function cmsDetectEmbedProvider(string $url): string
{
    if (preg_match('/youtube\.com\/watch|youtu\.be\//', $url)) return 'youtube';
    if (preg_match('/vimeo\.com\//', $url)) return 'vimeo';
    if (preg_match('/twitter\.com\/|x\.com\//', $url)) return 'twitter';
    if (preg_match('/instagram\.com\//', $url)) return 'instagram';
    return 'generic';
}

/**
 * Generate responsive embed HTML for known providers.
 */

function cmsGenerateEmbedHtml(string $url, string $provider): string
{
    $esc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    if ($provider === 'youtube') {
        $videoId = '';
        if (preg_match('/[?&]v=([^&]+)/', $url, $m)) $videoId = $m[1];
        elseif (preg_match('/youtu\.be\/([^?]+)/', $url, $m)) $videoId = $m[1];
        if ($videoId !== '') {
            return '<div class="cms-embed-responsive"><iframe src="https://www.youtube.com/embed/' . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') . '" frameborder="0" allowfullscreen loading="lazy"></iframe></div>';
        }
    }
    if ($provider === 'vimeo') {
        if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
            return '<div class="cms-embed-responsive"><iframe src="https://player.vimeo.com/video/' . $m[1] . '" frameborder="0" allowfullscreen loading="lazy"></iframe></div>';
        }
    }
    return '<a href="' . $esc . '" target="_blank" rel="noopener noreferrer">' . $esc . '</a>';
}
