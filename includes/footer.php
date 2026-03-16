    </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/cargochina/frontend/js/upload-utils.js"></script>
    <script src="/cargochina/frontend/js/app.js"></script>
    <script src="/cargochina/frontend/js/sidebar.js?v=<?= @filemtime(__DIR__ . '/../frontend/js/sidebar.js') ?: time() ?>"></script>
    <script>
      (function() {
        var lang = typeof localStorage !== 'undefined' ? (localStorage.getItem('clms_desc_lang') || 'en') : 'en';
        document.querySelectorAll('.desc-lang-btn').forEach(function(btn) {
          btn.classList.toggle('active', btn.dataset.lang === lang);
          btn.addEventListener('click', function() {
            var l = this.dataset.lang;
            if (typeof localStorage !== 'undefined') localStorage.setItem('clms_desc_lang', l);
            document.querySelectorAll('.desc-lang-btn').forEach(function(b) {
              b.classList.toggle('active', b.dataset.lang === l);
            });
            window.location.reload();
          });
        });
      })();
    </script>
    <script>
      (function() {
        var b = document.getElementById('notifBadge');
        if (b) {
          fetch('/cargochina/api/v1/notifications', {
              credentials: 'same-origin'
            })
            .then(function(r) {
              return r.json();
            })
            .then(function(d) {
              var rows = d.data || [];
              var unread = rows.filter(function(n) {
                return !n.read_at;
              });
              if (unread.length > 0) {
                b.textContent = unread.length;
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
