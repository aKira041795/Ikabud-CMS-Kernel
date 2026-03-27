<?php

declare(strict_types=1);

function cmsSeoEscape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cmsSeoStrip(string $s): string
{
    $s = strip_tags($s);
    // Remove shortcode-style placeholders such as [contact-form] or [gallery id="1"]
    $s = preg_replace('/\[[^\]]+\]/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string)$s);
}

function cmsSeoAbsoluteUploadUrl(string $path, string $appUrl): string
{
    if ($path === '') {
        return '';
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    $resolved = cmsResolveUploadUrl($path);
    if ($resolved === '') {
        return '';
    }

    if ($appUrl !== '' && str_starts_with($resolved, '/')) {
        return $appUrl . $resolved;
    }

    return $resolved;
}

/**
 * Build default SEO head HTML for a public CMS content page.
 *
 * Reads:
 * - $content['type'], $content['slug'], $content['title'], $content['excerpt']
 * - $content['featured_image'] (file path) OR $content['featured_image_path']
 * - $content['meta']['seo_title'], $content['meta']['seo_description'] (if provided)
 */

function cmsDefaultSeoHeadHtml(array $content = []): string
{
    $type = (string)($content['type'] ?? '');
    $slug = (string)($content['slug'] ?? '');
    $title = (string)($content['title'] ?? '');
    $excerpt = (string)($content['excerpt'] ?? '');
    $meta = $content['meta'] ?? [];
    if (!is_array($meta)) $meta = [];

    // CMS-wide SEO settings as fallbacks
    $cmsSettings = readCmsSettings();
    $siteSeoDesc = trim((string)($cmsSettings['seo_meta_description'] ?? ''));
    $siteSeoOgImage = trim((string)($cmsSettings['seo_og_image'] ?? ''));
    $siteSeoRobots = trim((string)($cmsSettings['seo_robots'] ?? 'index, follow'));
    $seoSeparator = trim((string)($cmsSettings['seo_title_separator'] ?? '|'));
    $siteTitle = trim((string)($cmsSettings['site_title'] ?? ''));

    // Builder SEO settings override per-content meta
    $builderSeo = [];
    $rawBuilderSeo = $meta['_builder_seo_settings'] ?? '';
    if (is_string($rawBuilderSeo) && trim($rawBuilderSeo) !== '') {
        $decoded = json_decode($rawBuilderSeo, true);
        if (is_array($decoded)) $builderSeo = $decoded;
    }

    $appUrl = external_base_url((string)app()->config('app.url', ''));
    $path = '';
    if ($type === 'post' && $slug !== '') {
        $path = '/cms/blog/' . $slug;
    } elseif ($type === 'page' && $slug !== '') {
        $path = '/cms/page/' . $slug;
    }
    $canonical = trim((string)($builderSeo['canonicalUrl'] ?? ''));
    if ($canonical === '' && $appUrl !== '' && $path !== '') {
        $canonical = $appUrl . $path;
    }

    // Title: builder meta → content meta → content title; append site title if available
    $seoTitle = trim((string)($builderSeo['metaTitle'] ?? ''));
    if ($seoTitle === '') $seoTitle = trim((string)($meta['seo_title'] ?? ''));
    if ($seoTitle === '') $seoTitle = $title;
    $fullTitle = $seoTitle;
    if ($siteTitle !== '' && $seoTitle !== '' && $seoTitle !== $siteTitle) {
        $fullTitle = $seoTitle . ' ' . $seoSeparator . ' ' . $siteTitle;
    }

    // Description: builder meta → content meta → excerpt → site default
    $seoDesc = trim((string)($builderSeo['metaDescription'] ?? ''));
    if ($seoDesc === '') $seoDesc = trim((string)($meta['seo_description'] ?? ''));
    if ($seoDesc === '') $seoDesc = $excerpt;
    if ($seoDesc === '') $seoDesc = $siteSeoDesc;
    $seoDesc = cmsSeoStrip($seoDesc);
    $seoDesc = cmsSeoStrip($seoDesc);
    if (strlen($seoDesc) > 160) {
        $seoDesc = substr($seoDesc, 0, 157) . '...';
    }

    // OG image: builder meta → featured image → site default
    $ogImage = '';
    $builderOgImage = trim((string)($builderSeo['ogImage'] ?? ''));
    if ($builderOgImage !== '') {
        $ogImage = $builderOgImage;
    } else {
        $imgPath = (string)($content['featured_image'] ?? ($content['featured_image_path'] ?? ''));
        if ($imgPath !== '') {
            $ogImage = cmsSeoAbsoluteUploadUrl($imgPath, $appUrl);
        } elseif ($siteSeoOgImage !== '') {
            $ogImage = $siteSeoOgImage;
        }
    }

    // Robots: builder meta → site default
    $noIndex = !empty($builderSeo['noIndex']);
    $noFollow = !empty($builderSeo['noFollow']);
    $robots = $siteSeoRobots;
    if ($noIndex || $noFollow) {
        $parts = [];
        $parts[] = $noIndex ? 'noindex' : 'index';
        $parts[] = $noFollow ? 'nofollow' : 'follow';
        $robots = implode(', ', $parts);
    }

    $out = [];
    if ($fullTitle !== '') {
        $out[] = '<meta name="title" content="' . cmsSeoEscape($fullTitle) . '">';
        $out[] = '<meta property="og:title" content="' . cmsSeoEscape(trim((string)($builderSeo['ogTitle'] ?? '')) ?: $seoTitle) . '">';
        $out[] = '<meta name="twitter:title" content="' . cmsSeoEscape(trim((string)($builderSeo['twitterTitle'] ?? '')) ?: $seoTitle) . '">';
    }
    if ($seoDesc !== '') {
        $out[] = '<meta name="description" content="' . cmsSeoEscape($seoDesc) . '">';
        $ogDesc = trim((string)($builderSeo['ogDescription'] ?? ''));
        $out[] = '<meta property="og:description" content="' . cmsSeoEscape($ogDesc !== '' ? $ogDesc : $seoDesc) . '">';
        $twDesc = trim((string)($builderSeo['twitterDescription'] ?? ''));
        $out[] = '<meta name="twitter:description" content="' . cmsSeoEscape($twDesc !== '' ? $twDesc : $seoDesc) . '">';
    }
    if ($canonical !== '') {
        $out[] = '<link rel="canonical" href="' . cmsSeoEscape($canonical) . '">';
        $out[] = '<meta property="og:url" content="' . cmsSeoEscape($canonical) . '">';
    }
    if ($type === 'post' || $type === 'page') {
        $ogType = trim((string)($builderSeo['ogType'] ?? ''));
        $out[] = '<meta property="og:type" content="' . cmsSeoEscape($ogType !== '' ? $ogType : 'article') . '">';
    }
    if ($ogImage !== '') {
        $out[] = '<meta property="og:image" content="' . cmsSeoEscape($ogImage) . '">';
        $twitterCard = trim((string)($builderSeo['twitterCard'] ?? ''));
        $out[] = '<meta name="twitter:card" content="' . cmsSeoEscape($twitterCard !== '' ? $twitterCard : 'summary_large_image') . '">';
        $twitterImage = trim((string)($builderSeo['twitterImage'] ?? ''));
        $out[] = '<meta name="twitter:image" content="' . cmsSeoEscape($twitterImage !== '' ? $twitterImage : $ogImage) . '">';
    }
    if ($robots !== '' && $type !== '') {
        $out[] = '<meta name="robots" content="' . cmsSeoEscape($robots) . '">';
    }

    return implode("\n", $out);
}

// ── CMS Sitemap ─────────────────────────────────────────────────────

function cmsBuildSitemapUrls(): array
{
    $appUrl = external_base_url((string)app()->config('app.url', ''));
    $urls = [];

    // Home
    if ($appUrl !== '') {
        $urls[] = [
            'loc' => $appUrl . '/cms',
            'lastmod' => gmdate('Y-m-d'),
            'changefreq' => 'daily',
            'priority' => '0.8',
        ];
    }

    try {
        $db = cmsDb();
        $stmt = $db->query(
            "SELECT id, type, slug, updated_at, published_at\n             FROM cms_content\n             WHERE " . cmsPublicVisibilitySql('cms_content') . " AND deleted_at IS NULL AND slug <> ''\n             ORDER BY COALESCE(updated_at, published_at) DESC, id DESC"
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $type = (string)($r['type'] ?? 'post');
            $slug = (string)($r['slug'] ?? '');
            if ($slug === '' || $appUrl === '') continue;
            $path = $type === 'page' ? '/cms/page/' . $slug : '/cms/blog/' . $slug;
            $last = (string)($r['updated_at'] ?? $r['published_at'] ?? '');
            $lastmod = $last !== '' ? gmdate('Y-m-d', strtotime($last)) : gmdate('Y-m-d');
            $urls[] = [
                'loc' => $appUrl . $path,
                'lastmod' => $lastmod,
                'changefreq' => $type === 'page' ? 'weekly' : 'daily',
                'priority' => $type === 'page' ? '0.6' : '0.7',
            ];
        }
    } catch (\Throwable $e) {
        // Fall back to minimal sitemap
    }

    return $urls;
}

function cmsBuildSitemapXml(): string
{
    $urls = cmsBuildSitemapUrls();
    $xml = [];
    $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $u) {
        $xml[] = '  <url>';
        $xml[] = '    <loc>' . cmsSeoEscape((string)$u['loc']) . '</loc>';
        if (!empty($u['lastmod'])) $xml[] = '    <lastmod>' . cmsSeoEscape((string)$u['lastmod']) . '</lastmod>';
        if (!empty($u['changefreq'])) $xml[] = '    <changefreq>' . cmsSeoEscape((string)$u['changefreq']) . '</changefreq>';
        if (!empty($u['priority'])) $xml[] = '    <priority>' . cmsSeoEscape((string)$u['priority']) . '</priority>';
        $xml[] = '  </url>';
    }
    $xml[] = '</urlset>';

    return implode("\n", $xml) . "\n";
}

/**
 * Get the current CMS user from the kernel JWT.
 * Returns null if the user is not a CMS-authenticated user.
 */

function cmsStructuredDataJsonLd(array $content): string
{
    $type = (string)($content['type'] ?? 'post');
    $title = (string)($content['title'] ?? '');
    $excerpt = (string)($content['excerpt'] ?? '');
    $slug = (string)($content['slug'] ?? '');
    $publishedAt = (string)($content['published_at'] ?? '');
    $updatedAt = (string)($content['updated_at'] ?? $publishedAt);
    $authorName = (string)($content['author_name'] ?? '');
    $featuredImage = (string)($content['featured_image'] ?? ($content['featured_image_path'] ?? ''));
    $meta = $content['meta'] ?? [];
    if (!is_array($meta)) $meta = [];

    $cmsSettings = readCmsSettings();
    $siteTitle = trim((string)($cmsSettings['site_title'] ?? ''));
    $appUrl = external_base_url((string)app()->config('app.url', ''));

    $path = '';
    if ($type === 'post' && $slug !== '') {
        $path = '/cms/blog/' . $slug;
    } elseif ($type === 'page' && $slug !== '') {
        $path = '/cms/page/' . $slug;
    }
    $canonical = ($appUrl !== '' && $path !== '') ? $appUrl . $path : '';

    $description = trim((string)($meta['seo_description'] ?? ''));
    if ($description === '') $description = $excerpt;
    if ($description === '') $description = cmsSeoStrip($content['body'] ?? '');
    $description = cmsSeoStrip($description);
    if (strlen($description) > 160) $description = substr($description, 0, 157) . '...';

    $data = ['@context' => 'https://schema.org'];

    if ($type === 'post') {
        $data['@type'] = 'Article';
        $data['headline'] = $title;
        $data['description'] = $description;
        if ($canonical !== '') $data['url'] = $canonical;
        if ($publishedAt !== '') $data['datePublished'] = date('c', strtotime($publishedAt));
        if ($updatedAt !== '') $data['dateModified'] = date('c', strtotime($updatedAt));
        if ($authorName !== '') {
            $data['author'] = ['@type' => 'Person', 'name' => $authorName];
        }
        if ($siteTitle !== '') {
            $data['publisher'] = ['@type' => 'Organization', 'name' => $siteTitle];
        }
        if ($featuredImage !== '') {
            $imgUrl = cmsSeoAbsoluteUploadUrl($featuredImage, $appUrl);
            $data['image'] = $imgUrl;
        }
    } else {
        $data['@type'] = 'WebPage';
        $data['name'] = $title;
        $data['description'] = $description;
        if ($canonical !== '') $data['url'] = $canonical;
    }

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return '';

    return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
}

// ── RSS Feed ──────────────────────────────────────────────────────────

/**
 * Build an RSS 2.0 XML feed from the latest published posts.
 *
 * @param int $limit Maximum items in the feed (default 20)
 * @return string Complete RSS 2.0 XML document
 */
function cmsBuildRssFeedXml(int $limit = 20): string
{
    $settings = readCmsSettings();
    $siteTitle    = trim((string)($settings['site_title'] ?? ''));
    $siteTagline  = trim((string)($settings['site_tagline'] ?? ''));
    $appUrl       = external_base_url((string)app()->config('app.url', ''));

    if ($siteTitle === '') $siteTitle = 'Blog';
    if ($appUrl === '')    $appUrl = rtrim(request_scheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');

    $db = cmsDb();
    $vis = cmsPublicVisibilitySql('c');
    $stmt = $db->prepare(
        "SELECT c.id, c.title, c.slug, c.type, c.excerpt, c.body, c.published_at, c.updated_at,
                c.author_id,
                u.display_name AS author_name
         FROM cms_content c
         LEFT JOIN cms_users u ON u.id = c.author_id
         WHERE c.deleted_at IS NULL AND c.type = 'post' AND {$vis}
         ORDER BY c.published_at DESC
         LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $xml = [];
    $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml[] = '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">';
    $xml[] = '  <channel>';
    $xml[] = '    <title>' . cmsSeoEscape($siteTitle) . '</title>';
    $xml[] = '    <link>' . cmsSeoEscape($appUrl . '/cms') . '</link>';
    $xml[] = '    <description>' . cmsSeoEscape($siteTagline) . '</description>';
    $xml[] = '    <language>en-us</language>';
    $xml[] = '    <atom:link href="' . cmsSeoEscape($appUrl . '/cms/feed') . '" rel="self" type="application/rss+xml"/>';

    if (!empty($posts)) {
        $xml[] = '    <lastBuildDate>' . gmdate('D, d M Y H:i:s +0000', strtotime($posts[0]['published_at'])) . '</lastBuildDate>';
    }

    foreach ($posts as $post) {
        $link = $appUrl . '/cms/blog/' . $post['slug'];
        $desc = trim((string)($post['excerpt'] ?? ''));
        if ($desc === '') {
            $desc = cmsSeoStrip((string)($post['body'] ?? ''));
            if (strlen($desc) > 300) $desc = substr($desc, 0, 297) . '...';
        }
        $pubDate = !empty($post['published_at'])
            ? gmdate('D, d M Y H:i:s +0000', strtotime($post['published_at']))
            : '';

        $xml[] = '    <item>';
        $xml[] = '      <title>' . cmsSeoEscape((string)($post['title'] ?? '')) . '</title>';
        $xml[] = '      <link>' . cmsSeoEscape($link) . '</link>';
        $xml[] = '      <guid isPermaLink="true">' . cmsSeoEscape($link) . '</guid>';
        $xml[] = '      <description>' . cmsSeoEscape($desc) . '</description>';
        if ($pubDate !== '') $xml[] = '      <pubDate>' . $pubDate . '</pubDate>';
        if (!empty($post['author_name'])) $xml[] = '      <dc:creator>' . cmsSeoEscape((string)$post['author_name']) . '</dc:creator>';
        $xml[] = '    </item>';
    }

    $xml[] = '  </channel>';
    $xml[] = '</rss>';

    return implode("\n", $xml) . "\n";
}

// ── Revision Pruning — adopted from ikabud-kernel ContentRevisionService ──

/**
 * Prune old builder revisions beyond a configurable max per document.
 * Keeps the newest $maxRevisions revisions; deletes the rest.
 */
