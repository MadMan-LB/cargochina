<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
require_once 'includes/downloads_registry.php';

requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'LebanonAdmin', 'WarehouseStaff', 'ContainersStaff', 'SuperAdmin']);

$currentPage = 'downloads';
$pageTitle = 'Downloads';
$userRoles = $_SESSION['user_roles'] ?? [];
$downloadSections = clmsVisibleDownloadsCatalog($userRoles);
$directFileCount = 0;
$generatedExportCount = 0;
foreach ($downloadSections as $section) {
    foreach ($section['entries'] as $entry) {
        if (($entry['mode'] ?? 'file') === 'file') {
            $directFileCount++;
        } else {
            $generatedExportCount++;
        }
    }
}

require 'includes/layout.php';
?>

<div class="downloads-hero card border-0 shadow-sm mb-4">
  <div class="card-body p-4 p-lg-5">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3">
      <div>
        <h1 class="mb-2">Downloads</h1>
        <p class="text-muted mb-0">Find verified Excel files, ready-to-download example workbooks, and the existing module export entry points without hunting through different CLMS pages.</p>
      </div>
      <div class="downloads-hero-stats d-flex flex-wrap gap-2">
        <span class="badge text-bg-light border px-3 py-2"><?= $directFileCount ?> direct file<?= $directFileCount === 1 ? '' : 's' ?></span>
        <span class="badge text-bg-light border px-3 py-2"><?= $generatedExportCount ?> generated export<?= $generatedExportCount === 1 ? '' : 's' ?></span>
      </div>
    </div>
  </div>
</div>

<?php if (!$downloadSections): ?>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h5 class="mb-2">No downloads available</h5>
      <p class="text-muted mb-0">No verified downloadable templates or export entry points are available for your current role.</p>
    </div>
  </div>
<?php else: ?>
  <div class="accordion downloads-accordion" id="downloadsAccordion">
    <?php foreach ($downloadSections as $index => $section): ?>
      <div class="accordion-item downloads-section-card mb-3 border-0 shadow-sm">
        <h2 class="accordion-header" id="downloadsHeading<?= $index ?>">
          <button class="accordion-button <?= $index === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#downloadsCollapse<?= $index ?>" aria-expanded="<?= $index === 0 ? 'true' : 'false' ?>" aria-controls="downloadsCollapse<?= $index ?>">
            <span>
              <span class="d-block fw-semibold"><?= htmlspecialchars($section['title']) ?></span>
              <span class="small text-muted"><?= htmlspecialchars($section['description']) ?></span>
            </span>
          </button>
        </h2>
        <div id="downloadsCollapse<?= $index ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>" aria-labelledby="downloadsHeading<?= $index ?>" data-bs-parent="#downloadsAccordion">
          <div class="accordion-body">
            <div class="row g-3">
              <?php foreach ($section['entries'] as $entry): ?>
                <?php
                $mode = (string) ($entry['mode'] ?? 'file');
                $isDownload = in_array($mode, ['file', 'generated'], true);
                ?>
                <div class="col-12 col-xl-6">
                  <div class="card h-100 download-item-card">
                    <div class="card-body d-flex flex-column gap-3">
                      <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                          <h5 class="mb-1"><?= htmlspecialchars($entry['title']) ?></h5>
                          <p class="text-muted small mb-0"><?= htmlspecialchars($entry['description']) ?></p>
                        </div>
                        <span class="download-file-pill"><?= htmlspecialchars($entry['file_type'] ?? 'FILE') ?></span>
                      </div>

                      <div class="download-meta d-flex flex-wrap gap-2">
                        <?php if ($mode === 'file'): ?>
                          <span class="badge text-bg-light border">Verified file</span>
                          <span class="badge text-bg-light border"><?= htmlspecialchars($entry['size_label'] ?? '—') ?></span>
                          <span class="badge text-bg-light border text-truncate" title="<?= htmlspecialchars($entry['relative_path'] ?? '') ?>"><?= htmlspecialchars($entry['relative_path'] ?? '') ?></span>
                        <?php elseif ($mode === 'generated'): ?>
                          <span class="badge text-bg-light border">Download-ready example</span>
                          <span class="badge text-bg-light border">Generated safely</span>
                        <?php else: ?>
                          <span class="badge text-bg-light border">Generated from module</span>
                          <span class="badge text-bg-light border">Existing export flow</span>
                        <?php endif; ?>
                      </div>

                      <div class="mt-auto d-flex flex-wrap gap-2">
                        <?php if ($isDownload): ?>
                          <a class="btn btn-primary btn-sm" href="<?= $basePath ?>/download_template.php?slug=<?= urlencode($entry['slug']) ?>" download>Download</a>
                        <?php else: ?>
                          <a class="btn btn-outline-primary btn-sm" href="<?= $basePath ?>/<?= htmlspecialchars(ltrim((string) ($entry['module_path'] ?? ''), '/')) ?>"><?= htmlspecialchars($entry['action_label'] ?? 'Open module') ?></a>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
