    </div>
    </div>
    <script>
      window.CLMS_UI = <?= json_encode($clientTranslations ?? clmsGetClientTranslationPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="/cargochina/frontend/js/bootstrap.bundle.min.js"></script>
    <script src="/cargochina/frontend/js/upload-utils.js?v=<?= @filemtime(__DIR__ . '/../frontend/js/upload-utils.js') ?: time() ?>"></script>
    <script src="/cargochina/frontend/js/app.js?v=<?= @filemtime(__DIR__ . '/../frontend/js/app.js') ?: time() ?>"></script>
    <script src="/cargochina/frontend/js/export_download.js?v=<?= @filemtime(__DIR__ . '/../frontend/js/export_download.js') ?: time() ?>"></script>
    <script src="/cargochina/frontend/js/incident_reporter.js"></script>
    <script src="/cargochina/frontend/js/sidebar.js?v=<?= @filemtime(__DIR__ . '/../frontend/js/sidebar.js') ?: time() ?>"></script>
    <?php
      require_once __DIR__.'/../backend/services/OwnerAccessService.php';
      try { $showOwnerControl=OwnerAccessService::allowed(getDb(),(int)($_SESSION['user_id']??0)); }
      catch(Throwable $e){$showOwnerControl=false;}
      if($showOwnerControl):
    ?>
    <a id="ownerIncidentNotice" class="btn btn-dark position-fixed bottom-0 end-0 m-3" style="z-index:1050" href="/cargochina/owner_control.php">Owner operations</a>
    <script src="/cargochina/frontend/js/owner_notice.js"></script>
    <?php endif; ?>
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
        <?php $scriptSrc = $s === '/cargochina/frontend/js/autocomplete.js'
            ? $s . '?v=' . filemtime(__DIR__ . '/../frontend/js/autocomplete.js')
            : $s; ?>
        <script src="<?= htmlspecialchars($scriptSrc) ?>"></script>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if (!empty($pageScript)): ?>
      <script src="<?= htmlspecialchars($pageScript) ?>"></script>
    <?php endif; ?>
    </body>

    </html>
