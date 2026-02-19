/**
 * Type-to-select autocomplete - customers, suppliers, products
 * Usage: Autocomplete.init(inputEl, { resource, renderItem, onSelect })
 */

const Autocomplete = {
    debounceMs: 250,
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
                "autocomplete-dropdown list-group position-absolute";
            dropdown.style.cssText =
                "max-height:200px;overflow-y:auto;z-index:1050;min-width:100%";
            items.forEach((item, i) => {
                const el = document.createElement("button");
                el.type = "button";
                el.className =
                    "list-group-item list-group-item-action text-start";
                el.textContent = renderItem(item);
                el.dataset.index = String(i);
                el.addEventListener("click", () => selectItem(i));
                dropdown.appendChild(el);
            });
            inputEl.parentNode.style.position = "relative";
            inputEl.parentNode.appendChild(dropdown);
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

        const fetchSearch = async (q) => {
            if (abortController) abortController.abort();
            abortController = new AbortController();
            try {
                const res = await fetch(
                    API_BASE +
                        "/" +
                        resource +
                        "/search?q=" +
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
            }, this.debounceMs);
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
                    inputEl.dataset.selectedId = "";
                }
            },
        };
    },

    defaultRender: {
        customers: (c) =>
            `${c.name || ""} — ${c.code || ""}`
                .replace(/^ — | — $/g, "")
                .trim() || `#${c.id}`,
        suppliers: (s) =>
            `${s.name || ""} — ${s.phone || ""} — ${s.code || s.store_id || ""}`
                .replace(/^ — | — $/g, "")
                .trim() || `#${s.id}`,
        products: (p) =>
            `${p.description_cn || p.description_en || ""} — ${p.hs_code || ""}`
                .replace(/^ — | — $/g, "")
                .trim() || `#${p.id}`,
    },
};
