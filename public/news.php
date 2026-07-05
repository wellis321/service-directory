<?php
declare(strict_types=1);
/**
 * public/news.php — Sector news, with optional council filter.
 *
 * News lives in `provider_news` (keyed by sp_number). There is no per-article
 * council column, so council association is derived by joining to `services`
 * on sp_number and filtering on `services.council_area`. A provider that
 * operates in several councils will surface for each of those councils.
 */
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';

$pdo = db();

// ── Councils that actually have news (cleaner picklist than all councils) ──
$councils = [];
try {
    $councils = $pdo->query("
        SELECT DISTINCT s.council_area
        FROM provider_news pn
        INNER JOIN services s ON s.sp_number = pn.sp_number
        WHERE pn.status = 'shown'
          AND s.council_area IS NOT NULL
          AND TRIM(s.council_area) != ''
        ORDER BY s.council_area
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException) {}

// ── Validate council filter against whitelist ─────────────────────────────
$council = trim((string) ($_GET['council'] ?? ''));
if ($council !== '' && !in_array($council, $councils, true)) {
    $council = '';
}

// ── Pagination ────────────────────────────────────────────────────────────
$per_page = 18;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = "pn.status = 'shown' AND pn.published_at IS NOT NULL";
$bind = [];
$join = "LEFT JOIN services s ON s.sp_number = pn.sp_number";
if ($council !== '') {
    // Inner join so only providers with a service in this council are counted.
    $join = "INNER JOIN services s ON s.sp_number = pn.sp_number AND s.council_area = :council";
    $bind[':council'] = $council;
}

$total = 0;
$news = [];
try {
    $countStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT pn.id)
        FROM provider_news pn
        $join
        WHERE $where
    ");
    $countStmt->execute($bind);
    $total = (int) $countStmt->fetchColumn();

    $pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $pages);
    $offset = ($page - 1) * $per_page;

    $listStmt = $pdo->prepare("
        SELECT pn.id, pn.title, pn.url, pn.source_name, pn.snippet,
               pn.published_at, pn.sp_number,
               MAX(s.provider_name) AS provider_name,
               MAX(s.council_area)  AS council_area
        FROM provider_news pn
        $join
        WHERE $where
        GROUP BY pn.id
        ORDER BY pn.published_at DESC
        LIMIT $per_page OFFSET $offset
    ");
    $listStmt->execute($bind);
    $news = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {
    $pages = 1;
}

$pages = $pages ?? 1;

// ── Pagination link builder (preserves the council filter) ─────────────────
$pageHref = static function (int $p) use ($council): string {
    $q = ['page' => $p];
    if ($council !== '') {
        $q['council'] = $council;
    }
    return '/news?' . http_build_query($q);
};

$title = ($council !== '' ? $council . ' care news' : 'Latest care sector news') . ' | CareScotland';
$heading = $council !== '' ? 'Care news — ' . $council : 'Latest care sector news';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="Recent press coverage of care providers across Scotland<?= $council !== '' ? ' in ' . h($council) : '' ?>, sourced daily from Google News.">
<link rel="stylesheet" href="/assets/style.css">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
</head>
<body>

<header class="site-header">
  <div class="container">
    <a href="/" class="logo"><span class="logo-icon"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 2.5 4.5 5v5.2c0 5.4 3.3 9.9 7.5 11.3 4.2-1.4 7.5-5.9 7.5-11.3V5L12 2.5Z" fill="currentColor"/><path d="M8.3 12.1l2.6 2.6 4.8-5.4" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg></span> CareScotland</a>
    <div class="nav-disclosure">
      <input type="checkbox" id="nav-toggle" class="nav-toggle-input">
      <label for="nav-toggle" class="nav-toggle" aria-label="Menu">☰</label>
      <nav class="site-header__nav">
      <a href="/">Directory</a>
      <a href="/insights">Insights</a>
      <a href="/councils">Council map</a>
      <a href="/news" aria-current="page">News</a>
      <a href="/provider/claim.php">Are you a provider?</a>
    </nav>
    </div>
  </div>
</header>

<main class="container news-main">
  <nav class="breadcrumb">
    <a href="/">Home</a> ›
    <?php if ($council !== ''): ?>
      <a href="/news">News</a> › <span><?= h($council) ?></span>
    <?php else: ?>
      <span>News</span>
    <?php endif; ?>
  </nav>

  <header class="news-page__header">
    <h1><?= h($heading) ?></h1>
    <p class="news-page__lead">Recent press coverage of care providers across Scotland, sourced daily from Google News.
      <?php if ($council !== ''): ?>Showing coverage of providers operating in <?= h($council) ?>.<?php endif; ?>
    </p>
  </header>

  <?php if ($councils): ?>
  <section class="news-filter" aria-label="Filter news by council">
    <form class="news-filter__form" method="get" action="/news">
      <label class="news-filter__label" for="news-council">Filter by council</label>
      <select id="news-council" name="council" onchange="this.form.submit()">
        <option value="">All councils</option>
        <?php foreach ($councils as $c): ?>
          <option value="<?= h((string) $c) ?>" <?= $council === $c ? 'selected' : '' ?>><?= h((string) $c) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn-primary">Apply</button></noscript>
    </form>
    <?php if ($council !== ''): ?>
      <a class="news-filter__clear" href="/news">Clear filter ✕</a>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <p class="news-page__count">
    <?php if ($total > 0): ?>
      <?= number_format($total) ?> article<?= $total === 1 ? '' : 's' ?><?= $council !== '' ? ' for ' . h($council) : '' ?>
    <?php endif; ?>
  </p>

  <?php if ($news): ?>
    <div class="news-grid">
      <?php foreach ($news as $article):
        $pubTs  = !empty($article['published_at']) ? strtotime((string) $article['published_at']) : null;
        $pubStr = $pubTs ? date('j M Y', $pubTs) : '';
      ?>
      <article class="news-card">
        <?php if (!empty($article['provider_name'])): ?>
          <a class="news-card__provider" href="/provider/<?= h((string) $article['sp_number']) ?>/<?= slug((string) $article['provider_name']) ?>">
            <?= h((string) $article['provider_name']) ?>
          </a>
        <?php endif; ?>
        <a class="news-card__title" href="<?= h((string) $article['url']) ?>" target="_blank" rel="noopener noreferrer">
          <?= h((string) $article['title']) ?>
        </a>
        <?php if (!empty($article['snippet'])): ?>
          <p class="news-card__snippet"><?= h((string) $article['snippet']) ?></p>
        <?php endif; ?>
        <div class="news-card__meta">
          <?php if (!empty($article['source_name'])): ?><span class="news-card__source"><?= h((string) $article['source_name']) ?></span><?php endif; ?>
          <?php if ($pubStr): ?>
            <?php if (!empty($article['source_name'])): ?><span class="news-card__sep">·</span><?php endif; ?>
            <time class="news-card__date" datetime="<?= h((string) ($article['published_at'] ?? '')) ?>"><?= $pubStr ?></time>
          <?php endif; ?>
          <?php if ($council === '' && !empty($article['council_area'])): ?>
            <span class="news-card__sep">·</span>
            <a class="news-card__council" href="/news?council=<?= h(rawurlencode((string) $article['council_area'])) ?>"><?= h((string) $article['council_area']) ?></a>
          <?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Pages">
      <span class="pagination__meta">Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page > 1): ?>
        <a class="pagination__step" rel="prev" href="<?= h($pageHref($page - 1)) ?>">←</a>
      <?php endif; ?>
      <?php
        $nums = [1, $pages];
        for ($i = max(2, $page - 2); $i <= min($pages - 1, $page + 2); $i++) {
            $nums[] = $i;
        }
        $nums = array_values(array_unique($nums));
        sort($nums, SORT_NUMERIC);
        $prev = 0;
        foreach ($nums as $p):
          if ($prev > 0 && $p > $prev + 1): ?>
            <span class="pagination__ellipsis" aria-hidden="true">…</span>
          <?php endif; ?>
          <?php if ($p === $page): ?>
            <span class="active" aria-current="page"><?= $p ?></span>
          <?php else: ?>
            <a href="<?= h($pageHref($p)) ?>"><?= $p ?></a>
          <?php endif; ?>
          <?php $prev = $p; ?>
      <?php endforeach; ?>
      <?php if ($page < $pages): ?>
        <a class="pagination__step" rel="next" href="<?= h($pageHref($page + 1)) ?>">→</a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>

  <?php else: ?>
    <div class="news-empty">
      <p>
        <?php if ($council !== ''): ?>
          No recent news for providers in <?= h($council) ?> yet. <a href="/news">View all sector news →</a>
        <?php else: ?>
          No news has been published yet. Check back soon.
        <?php endif; ?>
      </p>
    </div>
  <?php endif; ?>

</main>

<footer class="site-footer site-footer--compact">
  <div class="container">
    <p>News sourced daily from Google News. Articles link to the original publisher. Service data from the <a href="https://www.careinspectorate.com" rel="noopener">Care Inspectorate</a> (Open Government Licence).</p>
    <p class="site-footer__admin"><a href="/admin/imports.php">Admin</a></p>
  </div>
</footer>

</body>
</html>
