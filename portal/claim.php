<?php
// =============================================================
// portal/claim.php — Provider registers and claims a listing
// (Lives under portal/ so /provider/{sp}/… URLs are not shadowed
//  by a physical provider/ directory on PHP’s built-in server.)
// =============================================================
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';

$pdo = db();
$cs  = strtoupper(trim($_GET['cs'] ?? ''));

// Load the service being claimed
$service = $cs ? get_service($cs) : null;

// Check if already claimed
$already_claimed = false;
if ($service) {
    $chk = $pdo->prepare("SELECT id FROM listing_tiers WHERE service_id = ?");
    $chk->execute([$service['id']]);
    $already_claimed = (bool)$chk->fetchColumn();
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company  = trim($_POST['company_name']  ?? '');
    $contact  = trim($_POST['contact_name']  ?? '');
    $email    = trim($_POST['email']         ?? '');
    $phone    = trim($_POST['phone']         ?? '');
    $password = $_POST['password']           ?? '';
    $cs_post  = strtoupper(trim($_POST['cs_number'] ?? $cs));

    if (!$company)                              $errors[] = 'Company name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (strlen($password) < 8)                  $errors[] = 'Password must be at least 8 characters.';
    if ($already_claimed)                       $errors[] = 'This service has already been claimed.';

    // Check email not already registered
    if (!$errors) {
        $exists = $pdo->prepare("SELECT id FROM providers WHERE email = ?");
        $exists->execute([$email]);
        if ($exists->fetchColumn()) $errors[] = 'An account with this email already exists. Please log in.';
    }

    if (!$errors) {
        // Create provider account
        $hash  = password_hash($password, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(32));

        $pdo->prepare("
            INSERT INTO providers (sp_number, company_name, contact_name, email, phone, password_hash, verify_token)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $service['sp_number'] ?? null,
            $company, $contact, $email, $phone, $hash, $token
        ]);
        $provider_id = (int)$pdo->lastInsertId();

        // Create free listing tier record
        if ($service) {
            $pdo->prepare("
                INSERT INTO listing_tiers (service_id, provider_id, tier, approved)
                VALUES (?, ?, 'free', 0)
            ")->execute([$service['id'], $provider_id]);
        }

        // Send verification email
        $cfg      = load_app_config();
        $verify   = $cfg['site_url'] . "/provider/verify.php?token=$token";
        send_email($email, 'Verify your CareScotland account', "
            <p>Hi $contact,</p>
            <p>Please verify your email to activate your listing for <strong>{$service['service_name']}</strong>.</p>
            <p><a href='$verify'>Verify my account →</a></p>
            <p>The CareScotland team will review your claim within 24 hours.</p>
        ");

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Claim your listing — CareScotland</title>
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
      <a href="/provider/claim.php" aria-current="page">Are you a provider?</a>
    </nav>
    </div>
  </div>
</header>

<div class="container narrow">

  <div class="claim-header">
    <h1>Claim your listing</h1>
    <?php if ($service): ?>
      <p>You're claiming: <strong><?= h($service['service_name']) ?></strong>, <?= h($service['town']) ?></p>
    <?php else: ?>
      <p>Create a provider account and claim your care service listing.</p>
    <?php endif; ?>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success">
      <h2>Account created!</h2>
      <p>Please check your email and click the verification link. We'll review your claim within 24 hours.</p>
      <p>Once approved, you can log in and start editing your profile, adding photos and vacancies.</p>
      <a href="/" class="btn-primary">Back to directory →</a>
    </div>

  <?php elseif ($already_claimed): ?>
    <div class="alert alert-warning">
      <p>This service listing has already been claimed. If you believe this is an error, please <a href="/contact">contact us</a>.</p>
    </div>

  <?php else: ?>

    <?php if ($errors): ?>
      <div class="alert alert-error">
        <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form class="claim-form" method="post">
      <input type="hidden" name="cs_number" value="<?= h($cs) ?>">

      <fieldset>
        <legend>Your account</legend>
        <label class="field-full">Organisation / company name *
          <input type="text" name="company_name" value="<?= h($_POST['company_name'] ?? $service['provider_name'] ?? '') ?>" required>
        </label>
        <label>Your name
          <input type="text" name="contact_name" value="<?= h($_POST['contact_name'] ?? '') ?>">
        </label>
        <label>Email address *
          <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
        </label>
        <label>Phone
          <input type="tel" name="phone" value="<?= h($_POST['phone'] ?? $service['phone'] ?? '') ?>">
        </label>
        <label>Password * (min 8 characters)
          <input type="password" name="password" required minlength="8">
        </label>
      </fieldset>

      <?php if (!$cs && !$service): ?>
      <fieldset>
        <legend>Your service</legend>
        <label class="field-full">CS number (from Care Inspectorate)
          <input type="text" name="cs_number" placeholder="e.g. CS2003000123">
        </label>
        <p class="field-hint">Find your CS number on the <a href="https://www.careinspectorate.com/index.php/care-services" target="_blank">Care Inspectorate website</a> or search above.</p>
      </fieldset>
      <?php endif; ?>

      <p class="terms-note">By registering you agree to our <a href="/terms">terms of service</a>. Listing claims are verified before going live.</p>
      <button type="submit" class="btn-primary">Create account and claim listing</button>
    </form>

    <div class="tier-preview">
      <h2>What's included</h2>
      <div class="tier-cards">
        <div class="tier-card">
          <h3>Free</h3>
          <p class="price">£0/month</p>
          <ul>
            <li>Verified badge</li>
            <li>Edit contact details</li>
            <li>Receive enquiries</li>
          </ul>
        </div>
        <div class="tier-card tier-card--highlight">
          <h3>Premium</h3>
          <p class="price">£29/month</p>
          <ul>
            <li>Everything in Free</li>
            <li>Photos gallery</li>
            <li>Vacancies & pricing</li>
            <li>Higher search ranking</li>
            <li>Website link</li>
          </ul>
        </div>
        <div class="tier-card">
          <h3>Pro</h3>
          <p class="price">£79/month</p>
          <ul>
            <li>Everything in Premium</li>
            <li>Top of search results</li>
            <li>Featured badge</li>
            <li>Analytics dashboard</li>
            <li>Grade benchmarking</li>
          </ul>
        </div>
      </div>
    </div>

  <?php endif; ?>
</div>

<footer class="site-footer">
  <div class="container">
    <p>CareScotland — helping families find great care in Scotland.</p>
    <p class="site-footer__admin"><a href="/admin/imports.php">Admin</a></p>
  </div>
</footer>
</body>
</html>
