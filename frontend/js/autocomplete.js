/**
 * Type-to-select autocomplete - customers, suppliers, products
 * Usage: Autocomplete.init(inputEl, { resource, renderItem, onSelect })
 */

const AUTOCOMPLETE_API_BASE =
    typeof window !== "undefined" && window.API_BASE
        ? window.API_BASE
        : "/cargochina/api/v1";

function formatAutocompleteParts(...parts) {
    return parts
        .map((part) => (part == null ? "" : String(part).trim()))
        .filter(Boolean)
        .join(" — ");
}

const Autocomplete = {
    debounceMs: 120,
    minChars: 1,

    init(inputEl, opts = {}) {
        if (!inputEl) return;
        const resource = opts.resource || "customers";
        const renderItem =
            opts.renderItem ||
            this.defaultRender[resource] ||
            ((i) => i.name || i.id);
        const onSelect = opts.onSelect || (() => {});
        const placeholder = opts.placeholder || "Type to search...";

        inputEl.setAttribute("autocomplete", "off");
        inputEl.setAttribute("placeholder", placeholder);
        let dropdown = null;
        let selectedIndex = -1;
        let items = [];
        let abortController = null;

        const hide = () => {
            if (dropdown) {
                dropdown.remove();
                dropdown = null;
            }
            selectedIndex = -1;
        };

        const show = (list) => {
            hide();
            items = list || [];
            if (items.length === 0) return;
            dropdown = document.createElement("div");
            dropdown.className =
                "autocomplete-dropdown list-group position-fixed";
            dropdown.style.cssText =
                "max-height:200px;overflow-y:auto;z-index:9999;min-width:200px;box-shadow:0 4px 12px rgba(0,0,0,0.15);background:#fff";
            items.forEach((item, i) => {
                const el = document.createElement("button");
                el.type = "button";
                el.className =
                    "list-group-item list-group-item-action text-start";
                el.textContent = renderItem(item);
                el.dataset.index = String(i);
                el.addEventListener("mousedown", (e) => {
                    e.preventDefault();
                    selectItem(i);
                });
                dropdown.appendChild(el);
            });
            document.body.appendChild(dropdown);
            const rect = inputEl.getBoundingClientRect();
            dropdown.style.left = rect.left + "px";
            dropdown.style.top = rect.bottom + 2 + "px";
            dropdown.style.width = Math.max(rect.width, 200) + "px";
            selectedIndex = 0;
            highlight(0);
        };

        const highlight = (idx) => {
            const btns = dropdown?.querySelectorAll("button");
            if (btns) {
                btns.forEach((b, i) => b.classList.toggle("active", i === idx));
            }
            selectedIndex = idx;
        };

        const selectItem = (idx) => {
            const item = items[idx];
            if (item) {
                inputEl.value = renderItem(item);
                inputEl.dataset.selectedId = String(item.id);
                inputEl.dataset.selectedJson = JSON.stringify(item);
                onSelect(item);
            }
            hide();
        };

        const searchPath = opts.searchPath || "/search";
        const fetchSearch = async (q) => {
            if (abortController) abortController.abort();
            abortController = new AbortController();
            try {
                const res = await fetch(
                    AUTOCOMPLETE_API_BASE +
                        "/" +
                        resource +
                        searchPath +
                        "?q=" +
                        encodeURIComponent(q),
                    {
                        credentials: "same-origin",
                        signal: abortController.signal,
                    },
                );
                const data = await res.json();
                return data.data || [];
            } catch (e) {
                if (e.name === "AbortError") return [];
                showToast &&
                    showToast(
                        "Search failed: " + (e.message || "Unknown error"),
                        "danger",
                    );
                return [];
            }
        };

        const debounceMs = opts.debounceMs ?? this.debounceMs;
        let debounceTimer;
        inputEl.addEventListener("input", () => {
            const q = inputEl.value.trim();
            if (q.length < this.minChars) {
                hide();
                delete inputEl.dataset.selectedId;
                delete inputEl.dataset.selectedJson;
                return;
            }
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(async () => {
                const list = await fetchSearch(q);
                show(list);
            }, debounceMs);
        });

        inputEl.addEventListener("focus", () => {
            const q = inputEl.value.trim();
            if (q.length >= this.minChars && items.length > 0) show(items);
        });

        inputEl.addEventListener("blur", () => {
            setTimeout(hide, 150);
        });

        inputEl.addEventListener("keydown", (e) => {
            if (!dropdown) return;
            if (e.key === "ArrowDown") {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                highlight(selectedIndex);
            } else if (e.key === "ArrowUp") {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, 0);
                highlight(selectedIndex);
            } else if (e.key === "Enter") {
                e.preventDefault();
                selectItem(selectedIndex);
            } else if (e.key === "Escape") {
                hide();
            }
        });

        return {
            getSelectedId: () => inputEl.dataset.selectedId || "",
            getSelected: () => {
                try {
                    return JSON.parse(inputEl.dataset.selectedJson || "null");
                } catch (_) {
                    return null;
                }
            },
            setValue: (item) => {
                if (item) {
                    inputEl.value = renderItem(item);
                    inputEl.dataset.selectedId = String(item.id);
                    inputEl.dataset.selectedJson = JSON.stringify(item);
                } else {
                    inputEl.value = "";
                    delete inputEl.dataset.selectedId;
                    delete inputEl.dataset.selectedJson;
                }
            },
        };
    },

    defaultRender: {
        customers: (c) => formatAutocompleteParts(c.name, c.code) || `#${c.id}`,
        suppliers: (s) =>
            formatAutocompleteParts(s.name, s.phone, s.code || s.store_id) ||
            `#${s.id}`,
        products: (p) =>
            formatAutocompleteParts(
                p.description_cn || p.description_en,
                p.hs_code,
            ) || `#${p.id}`,
        orders: (o) =>
            formatAutocompleteParts(
                `#${o.id}`,
                o.customer_name,
                o.expected_ready_date,
                o.status,
            ) || `#${o.id}`,
        containers: (c) =>
            formatAutocompleteParts(c.code, `#${c.id}`, c.status) || `#${c.id}`,
        expenses: (p) => p.payee || p.name || String(p.id || ""),
    },
};
