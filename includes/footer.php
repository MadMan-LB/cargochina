    </div>
    </div>
    <script>
      window.CLMS_UI = <?= json_encode($clientTranslations ?? clmsGetClientTranslationPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="/cargochina/frontend/js/bootstrap.bundle.min.js"></script>
    <script src="/cargochina/frontend/js/upload-utils.js?v=<?= @filemtime(__DIR__ . '/../frontend/js/upload-utils.js') ?: time() ?>"></script>
    <script src="/cargochina/frontend/js/app.js?v=<?= @filemtime(__DIR__ . '/../frontend/js/app.js') ?: time() ?>"></script>
    <script src="/cargochina/frontend/js/sidebar.js?v=<?= @filemtime(__DIR__ . '/../frontend/js/sidebar.js') ?: time() ?>"></script>
    <script>
      (function() {
        var b = document.getElementById('notifBadge');
        if (b) {
          fetch('/cargochina/api/v1/notifications/unread-count', {
              credentials: 'same-origin'
            })
            .then(function(r) {
              return r.json();
            })
            .then(function(d) {
              var unread = Number(d.data && d.data.unread_count || 0);
              if (unread > 0) {
                b.textContent = unread;
                b.classList.remove('d-none');
              }
            }).catch(function() {});
        }
      })();
    </script>
    <?php if (!empty($pageScripts) && is_array($pageScripts)): ?>
      <?php foreach ($pageScripts as $s): ?>
        <script src="<?= htmlspecialchars($s) ?>"></script>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if (!empty($pageScript)): ?>
      <script src="<?= htmlspecialchars($pageScript) ?>"></script>
    <?php endif; ?>
    </body>

    </html>
