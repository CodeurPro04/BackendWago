<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Retour paiement</title>
    <style>
      body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; padding: 24px; }
      .card { max-width: 540px; margin: 0 auto; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; }
      .muted { color: #6b7280; }
      a { color: #2563eb; word-break: break-all; }
      button { background: #2563eb; color: #fff; border: 0; border-radius: 10px; padding: 10px 14px; cursor: pointer; }
    </style>
  </head>
  <body>
    <div class="card">
      <h1>Retour paiement</h1>
      <p class="muted">Redirection vers l’application…</p>
      <p><a id="openLink" href="{{ $targetUrl }}">Ouvrir l’application</a></p>
      <button type="button" id="openBtn">Ouvrir</button>
    </div>
    <script>
      (function () {
        var url = document.getElementById('openLink').href;
        document.getElementById('openBtn').addEventListener('click', function () {
          window.location.href = url;
        });
        window.location.href = url;
      })();
    </script>
  </body>
</html>

