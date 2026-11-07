<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#4f46e5" />
    <meta name="description" content="Degeris Web App: services, renewals and wallet" />
    <title>Degeris Web App</title>
    <script src="./js/telegram-web-app.js"></script>
    <script type="module" crossorigin src="./assets/index-C-2a0Dur.js"></script>
    <link rel="modulepreload" crossorigin href="./assets/vendor-CIGJ9g2q.js">
    <link rel="stylesheet" crossorigin href="./assets/index-BoHBsj0Z.css">
    <link rel="stylesheet" href="./assets/degeris-theme.css">
  </head>
  <body>
    <div id="dg-splash" aria-hidden="true">
      <div class="dg-logo">D</div>
      <div class="dg-name">Degeris</div>
      <div class="dg-sub">در حال بارگذاری…</div>
      <div class="dg-bar"></div>
    </div>
    <div id="root"></div>
    <script>
      (function () {
        var splash = document.getElementById('dg-splash');
        var root = document.getElementById('root');
        if (!splash) return;
        function hide() {
          splash.classList.add('dg-hide');
          setTimeout(function () { splash.remove(); }, 450);
        }
        var timer = setInterval(function () {
          if (root && root.children.length) {
            clearInterval(timer);
            setTimeout(hide, 250);
          }
        }, 120);
        setTimeout(function () { clearInterval(timer); hide(); }, 9000);
      })();
    </script>
  </body>
</html>
