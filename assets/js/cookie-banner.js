(function () {
  var STORAGE_KEY = 'cookie-notice-dismissed';

  if (localStorage.getItem(STORAGE_KEY) === '1') return;

  var banner = document.createElement('div');
  banner.className = 'cookie-banner';
  banner.setAttribute('role', 'region');
  banner.setAttribute('aria-label', 'Cookie notice');
  banner.innerHTML =
    '<p>We don’t use advertising or tracking cookies. This site remembers a couple of simple ' +
    'preferences on your device and loads fonts, maps and charts from third-party services — see our ' +
    '<a href="/privacy">privacy policy</a> for details.</p>' +
    '<button type="button" class="cookie-banner__btn">Got it</button>';

  document.body.appendChild(banner);

  banner.querySelector('.cookie-banner__btn').addEventListener('click', function () {
    localStorage.setItem(STORAGE_KEY, '1');
    banner.remove();
  });
})();
