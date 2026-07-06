<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';

$title = 'Privacy policy | CareScotland';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="How CareScotland collects, uses and protects your personal data.">
<link rel="stylesheet" href="<?= asset_url('/assets/style.css') ?>">
<link rel="icon" type="image/svg+xml" href="<?= asset_url('/assets/favicon.svg') ?>">
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
      <a href="/news">News</a>
      <a href="/provider/claim.php">Are you a provider?</a>
    </nav>
    </div>
  </div>
</header>

<main class="container narrow legal-page">
  <nav class="breadcrumb"><a href="/">Home</a> › <span>Privacy policy</span></nav>

  <h1>Privacy policy</h1>
  <p class="legal-updated">Last updated: 6 July 2026</p>

  <p>This policy explains what personal data CareScotland collects, why, and what your rights are. CareScotland is operated by an individual sole trader ("we", "us"), who is the data controller for the personal data described below.</p>

  <h2>What we collect</h2>
  <p><strong>If you send an enquiry to a care service through this site:</strong> your name, email address, phone number (if given), your message, an optional care-start date, and your IP address (kept for basic abuse prevention).</p>
  <p><strong>If you create a provider account to claim a listing:</strong> your company name, contact name, email address, phone number, and a password (stored as a one-way cryptographic hash — we never store or see your actual password).</p>
  <p><strong>Standard web server logs:</strong> like almost any website, our hosting provider logs basic technical data (IP address, browser type, pages requested) for security and reliability. We don't layer any additional analytics or tracking on top of this.</p>

  <h2>How we use it</h2>
  <ul>
    <li><strong>Enquiries</strong> are forwarded directly to the care service or provider you contacted, so they can respond to you. We don't use enquiry data for marketing.</li>
    <li><strong>Provider account details</strong> are used to verify your claim to a listing, manage your account, and administer your subscription if you're on a paid tier.</li>
  </ul>
  <p>Our legal basis for processing this data is that it's necessary to provide the service you've asked for (fulfilling your enquiry, or operating the account you created), or our legitimate interest in running the directory securely.</p>

  <h2>Care Inspectorate data</h2>
  <p>The service and provider information shown across this directory (names, addresses, inspection grades, etc.) comes from the Care Inspectorate's own published open data under the Open Government Licence. This is public regulatory information about organisations, not personal data about you as a visitor.</p>

  <h2>Third-party services</h2>
  <p>Pages on this site load a small number of resources directly from third parties, which involves your browser making a request to their servers:</p>
  <ul>
    <li><strong>Google Fonts</strong> — for the typefaces used on this site.</li>
    <li><strong>jsDelivr</strong> — a CDN that serves the Chart.js library used for grade charts.</li>
    <li><strong>OpenStreetMap</strong> — map tiles used on the council map page.</li>
  </ul>
  <p>We don't control these providers' own data practices; their own privacy policies apply to requests your browser makes to them. None of them are used by us for advertising or tracking.</p>

  <h2>Cookies and local storage</h2>
  <p>We don't use advertising or analytics cookies, and we don't set any tracking cookies. The homepage remembers whether you last viewed results as cards or a table using your browser's local storage — this stays on your device, is never sent to us, and isn't used to identify or track you.</p>

  <h2>How long we keep it</h2>
  <p>Enquiry records are kept for as long as reasonably needed to resolve any dispute or query about that enquiry, then deleted. Provider account data is kept for as long as your account is active, plus a reasonable period afterward for our records, unless you ask us to delete it sooner.</p>

  <h2>Your rights</h2>
  <p>Under UK data protection law, you can ask us to: tell you what personal data we hold about you; correct it if it's wrong; delete it; or export it. To do any of these, email us using the address below — we'll respond within a reasonable time.</p>

  <h2>Security</h2>
  <p>Passwords are stored using industry-standard one-way hashing, never in plain text. We take reasonable technical measures to protect the data we hold, but no online service can guarantee absolute security.</p>

  <h2>Changes to this policy</h2>
  <p>We may update this policy from time to time; the "last updated" date at the top will change when we do.</p>

  <h2>Contact</h2>
  <p>Questions about this policy, or want to exercise your data rights? Email <a href="mailto:hello@carescotland.example">hello@carescotland.example</a>.</p>
</main>

<footer class="site-footer">
  <div class="container">
    <p>CareScotland — helping families find great care in Scotland.</p>
    <p class="site-footer__legal"><a href="/terms">Terms</a> · <a href="/privacy">Privacy</a></p>
    <p class="site-footer__admin"><a href="/admin/imports.php">Admin</a></p>
  </div>
</footer>
<script src="<?= asset_url('/assets/js/cookie-banner.js') ?>" defer></script>
</body>
</html>
