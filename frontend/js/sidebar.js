(function () {
    var sidebar = document.getElementById("sidebar");
    var main = document.getElementById("mainContent");
    var toggle = document.getElementById("sidebarToggle");
    var backdrop = document.getElementById("sidebarBackdrop");
    var closeBtn = document.getElementById("sidebarClose");
    var STORAGE_KEY = "clms_sidebar_collapsed";

    function isMobile() {
        return window.innerWidth < 992;
    }

    function applyState() {
        if (isMobile()) {
            sidebar.classList.remove("collapsed");
            sidebar.classList.remove("open");
            main.classList.remove("sidebar-collapsed");
        } else {
            var collapsed = localStorage.getItem(STORAGE_KEY) === "1";
            sidebar.classList.toggle("collapsed", collapsed);
            main.classList.toggle("sidebar-collapsed", collapsed);
        }
    }

    function toggleSidebar() {
        if (isMobile()) {
            var isOpen = sidebar.classList.contains("open");
            sidebar.classList.toggle("open", !isOpen);
            backdrop.classList.toggle("show", !isOpen);
        } else {
            var collapsed = sidebar.classList.contains("collapsed");
            sidebar.classList.toggle("collapsed", !collapsed);
            main.classList.toggle("sidebar-collapsed", !collapsed);
            localStorage.setItem(STORAGE_KEY, !collapsed ? "1" : "0");
        }
    }

    function closeMobile() {
        sidebar.classList.remove("open");
        backdrop.classList.remove("show");
    }

    if (toggle) toggle.addEventListener("click", toggleSidebar);
    if (backdrop) backdrop.addEventListener("click", closeMobile);
    if (closeBtn) closeBtn.addEventListener("click", closeMobile);

    applyState();
    window.addEventListener("resize", applyState);
})();
