<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';

$title = 'Terms of service | CareScotland';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="Terms of service for using the CareScotland care service directory.">
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
      <a href="/news">News</a>
      <a href="/provider/claim.php">Are you a provider?</a>
    </nav>
    </div>
  </div>
</header>

<main class="container narrow legal-page">
  <nav class="breadcrumb"><a href="/">Home</a> › <span>Terms of service</span></nav>

  <h1>Terms of service</h1>
  <p class="legal-updated">Last updated: 6 July 2026</p>

  <p>CareScotland is a directory of registered care services in Scotland, operated by an individual sole trader trading as "CareScotland" ("we", "us"). By using this site, you agree to these terms.</p>

  <h2>What this site is</h2>
  <p>CareScotland publishes information about care services registered with the Care Inspectorate, built from the Care Inspectorate's own open data (published under the Open Government Licence). We refresh this data monthly. We are not affiliated with, endorsed by, or acting on behalf of the Care Inspectorate.</p>

  <h2>Data accuracy</h2>
  <p>Inspection grades and service details are only as current as our last data refresh, and the Care Inspectorate's own publication schedule for that service. Some services shown may not have been reinspected recently — where we know this, we show a notice on the service's page. Always check the <a href="https://www.careinspectorate.com" target="_blank" rel="noopener">Care Inspectorate's website</a> directly before making a decision about care, and do not rely solely on information from this site.</p>

  <h2>Using the directory</h2>
  <p>The directory is free to browse and search. Nothing on this site is a recommendation, referral, or endorsement of any specific service or provider — it is a presentation of publicly available regulatory information to help you do your own research.</p>

  <h2>Enquiries</h2>
  <p>If you submit an enquiry through a service's contact form, we forward your message (name, contact details, and message content) directly to that service or provider so they can respond to you. We are not party to, and are not responsible for, any care arrangement, contract, or communication that follows between you and a provider.</p>

  <h2>Provider accounts and listings</h2>
  <p>Care providers may create an account to claim a free listing, or upgrade to a paid tier for additional features. By claiming a listing you confirm that you are authorised to represent that service, and that the information you submit is accurate. We review claims before they go live and may reject or remove a claim or listing at our discretion, including if we believe it is inaccurate, fraudulent, or in breach of these terms.</p>
  <p>Paid tiers are billed on a recurring basis while active. You may cancel at any time; cancellation takes effect at the end of the current billing period.</p>

  <h2>Acceptable use</h2>
  <p>You agree not to misuse this site — including scraping or bulk-downloading content beyond normal browsing, attempting to access non-public areas without authorisation, or submitting false, misleading, or abusive content through any form on this site.</p>

  <h2>Liability</h2>
  <p>This site is provided "as is". We do not guarantee that information is complete, accurate, or up to date, and we accept no liability for decisions made in reliance on it. Nothing on this site constitutes medical, legal, or care advice.</p>

  <h2>Changes to these terms</h2>
  <p>We may update these terms from time to time. Continued use of the site after a change means you accept the updated terms.</p>

  <h2>Contact</h2>
  <p>Questions about these terms? Email <a href="mailto:hello@carescotland.example">hello@carescotland.example</a>.</p>
</main>

<footer class="site-footer">
  <div class="container">
    <p>CareScotland — helping families find great care in Scotland.</p>
    <p class="site-footer__legal"><a href="/terms">Terms</a> · <a href="/privacy">Privacy</a></p>
    <p class="site-footer__admin"><a href="/admin/imports.php">Admin</a></p>
  </div>
</footer>
<script src="/assets/js/cookie-banner.js" defer></script>
</body>
</html>
