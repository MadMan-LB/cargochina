(function () {
    var sidebar = document.getElementById("sidebar");
    var main = document.getElementById("mainContent");
    var toggle = document.getElementById("sidebarToggle");
    var backdrop = document.getElementById("sidebarBackdrop");
    var closeBtn = document.getElementById("sidebarClose");
    var STORAGE_KEY = "clms_sidebar_collapsed";
    var OVERLAY_BREAKPOINT = 1200;

    function usesOverlaySidebar() {
        return window.innerWidth < OVERLAY_BREAKPOINT;
    }

    function syncStateAttributes() {
        var overlayMode = usesOverlaySidebar();
        var isOpen = overlayMode ? sidebar.classList.contains("open") : !sidebar.classList.contains("collapsed");

        document.body.classList.toggle("sidebar-overlay-mode", overlayMode);
        document.body.classList.toggle("sidebar-overlay-active", overlayMode && isOpen);

        if (toggle) {
            toggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
        }

        if (sidebar) {
            sidebar.setAttribute("aria-hidden", overlayMode && !isOpen ? "true" : "false");
        }
    }

    function applyState() {
        if (usesOverlaySidebar()) {
            sidebar.classList.remove("collapsed");
            sidebar.classList.remove("open");
            main.classList.remove("sidebar-collapsed");
            backdrop.classList.remove("show");
        } else {
            var collapsed = localStorage.getItem(STORAGE_KEY) === "1";
            sidebar.classList.remove("open");
            sidebar.classList.toggle("collapsed", collapsed);
            main.classList.toggle("sidebar-collapsed", collapsed);
            backdrop.classList.remove("show");
        }

        syncStateAttributes();
    }

    function toggleSidebar() {
        if (usesOverlaySidebar()) {
            var isOpen = sidebar.classList.contains("open");
            sidebar.classList.toggle("open", !isOpen);
            backdrop.classList.toggle("show", !isOpen);
        } else {
            var collapsed = sidebar.classList.contains("collapsed");
            sidebar.classList.toggle("collapsed", !collapsed);
            main.classList.toggle("sidebar-collapsed", !collapsed);
            localStorage.setItem(STORAGE_KEY, !collapsed ? "1" : "0");
        }

        syncStateAttributes();
    }

    function closeOverlaySidebar() {
        sidebar.classList.remove("open");
        backdrop.classList.remove("show");
        syncStateAttributes();
    }

    function closeOnEscape(event) {
        if (event.key === "Escape" && usesOverlaySidebar() && sidebar.classList.contains("open")) {
            closeOverlaySidebar();
        }
    }

    if (toggle) toggle.addEventListener("click", toggleSidebar);
    if (backdrop) backdrop.addEventListener("click", closeOverlaySidebar);
    if (closeBtn) closeBtn.addEventListener("click", closeOverlaySidebar);
    document.addEventListener("keydown", closeOnEscape);

    applyState();
    window.addEventListener("resize", applyState);
})();
