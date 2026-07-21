(function () {
    function translate(text, replacements = null) {
        return typeof window.t === "function" ? window.t(text, replacements) : text;
    }

    function filenameFromResponse(response, fallback) {
        const disposition = response.headers.get("Content-Disposition") || "";
        const utf8 = disposition.match(/filename\*=UTF-8''([^;]+)/i);
        if (utf8) return decodeURIComponent(utf8[1].replace(/["']/g, ""));
        const plain = disposition.match(/filename="?([^";]+)"?/i);
        return plain ? plain[1] : fallback;
    }

    function downloadBlob(blob, filename) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1500);
    }

    window.ClmsBulkExcelDownload = {
        create(options) {
            const state = {
                selected: new Set(),
                visible: new Set(),
                busy: false,
            };

            const button = () => document.getElementById(options.buttonId);
            const count = () => document.getElementById(options.countId);
            const selectAll = () => document.getElementById(options.selectAllId);
            const checkboxes = () =>
                Array.from(document.querySelectorAll(options.checkboxSelector));

            function sync() {
                const visibleSelected = Array.from(state.visible).filter((id) =>
                    state.selected.has(id),
                ).length;
                const totalVisible = state.visible.size;
                const selectAllNode = selectAll();
                if (selectAllNode) {
                    selectAllNode.checked =
                        totalVisible > 0 && visibleSelected === totalVisible;
                    selectAllNode.indeterminate =
                        visibleSelected > 0 && visibleSelected < totalVisible;
                    selectAllNode.disabled = totalVisible === 0 || state.busy;
                }
                checkboxes().forEach((node) => {
                    const id = String(node.dataset.downloadId || "");
                    node.checked = state.selected.has(id);
                    node.disabled = state.busy;
                });
                const buttonNode = button();
                if (buttonNode) {
                    buttonNode.disabled = state.selected.size === 0 || state.busy;
                }
                const countNode = count();
                if (countNode) {
                    countNode.textContent = translate("{count} selected", {
                        count: state.selected.size,
                    });
                }
            }

            function bind() {
                const nodes = checkboxes();
                state.visible = new Set(
                    nodes
                        .map((node) => String(node.dataset.downloadId || ""))
                        .filter(Boolean),
                );
                state.selected = new Set(
                    Array.from(state.selected).filter((id) => state.visible.has(id)),
                );
                nodes.forEach((node) => {
                    node.onchange = () => {
                        const id = String(node.dataset.downloadId || "");
                        if (!id) return;
                        if (node.checked) state.selected.add(id);
                        else state.selected.delete(id);
                        sync();
                    };
                });
                const selectAllNode = selectAll();
                if (selectAllNode) {
                    selectAllNode.onchange = () => {
                        state.visible.forEach((id) => {
                            if (selectAllNode.checked) state.selected.add(id);
                            else state.selected.delete(id);
                        });
                        sync();
                    };
                }
                sync();
            }

            async function download() {
                if (state.busy || !state.selected.size) return;
                state.busy = true;
                sync();
                const buttonNode = button();
                const originalLabel = buttonNode?.textContent || "";
                if (buttonNode) buttonNode.textContent = translate("Preparing download...");
                try {
                    const response = await fetch(options.endpoint, {
                        method: "POST",
                        credentials: "same-origin",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ ids: Array.from(state.selected) }),
                    });
                    if (!response.ok) {
                        let message = translate("Download failed");
                        try {
                            const payload = await response.json();
                            message = payload.message || message;
                        } catch (_) {}
                        throw new Error(message);
                    }
                    const filename = filenameFromResponse(
                        response,
                        state.selected.size === 1
                            ? "selected_order.xlsx"
                            : "selected_orders.xlsx",
                    );
                    downloadBlob(await response.blob(), filename);
                    if (typeof window.showToast === "function") {
                        window.showToast(translate("Download ready"), "success");
                    }
                } catch (error) {
                    if (typeof window.showToast === "function") {
                        window.showToast(error.message, "danger");
                    } else {
                        window.alert(error.message);
                    }
                } finally {
                    state.busy = false;
                    if (buttonNode) buttonNode.textContent = originalLabel;
                    sync();
                }
            }

            button()?.addEventListener("click", download);
            return { bind, sync, download, selected: state.selected };
        },
    };
})();
