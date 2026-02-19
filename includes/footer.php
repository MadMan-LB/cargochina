  </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="frontend/js/app.js"></script>
  <script>
    (function() {
        var b = document.getElementById('notifBadge');
        var p = document.getElementById('notifPlaceholder');
        if (b && p) {
          fetch('/cargochina/api/v1/notifications', {
            credentials: 'same-origin'
          }).then(r => r.json()).then(d => {
            var rows = d.data || [];
            var unread = rows.filter(n => !n.read_at);
            if (unread.length > 0) {
              b.textContent = unread.length;
              b.classList.remove('d-none');
            }
            p.innerHTML = unread.length ? unread.slice(0, 5).map(n => '<a class="dropdown-item" href="notifications.php">' + n.title + '</a>').join('') : 'No new notifications';
          }).catch(() => {
            p.textContent = 'Unable to load';
          });
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