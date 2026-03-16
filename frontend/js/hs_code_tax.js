const HS_CODE_TAX_API = window.API_BASE || "/cargochina/api/v1";
let hsCodeTaxSearchTimer = null;
let catalogSearchTimer = null;

function hsCodeTaxNotify(message, variant = "success") {
    if (typeof showToast === "function") {
        showToast(message, variant);
        return;
    }
    window.alert(message);
}

async function hsCodeTaxApi(method, path, body) {
    const options = {
        method,
        credentials: "same-origin",
        headers: { Accept: "application/json" },
    };
    if (body !== undefined) {
        options.headers["Content-Type"] = "application/json";
        options.body = JSON.stringify(body);
    }
    const response = await fetch(HS_CODE_TAX_API + path, options);
    const data = await response.json();
    if (!response.ok || data.error) {
        throw new Error(data.message || "Request failed");
    }
    return data;
}

function escapeHsHtml(value) {
    const div = document.createElement("div");
    div.textContent = value == null ? "" : String(value);
    return div.innerHTML;
}

function formatHsAmount(value) {
    const num = Number(value || 0);
    return num.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    });
}

function setCatalogSearchSummary(message) {
    const summaryEl = document.getElementById("catalogSearchSummary");
    if (summaryEl) {
        summaryEl.textContent = message;
    }
}

function currentTaxRateQuery() {
    const params = new URLSearchParams();
    const q = document.getElementById("taxRateSearch")?.value?.trim() || "";
    const country =
        document.getElementById("taxRateCountryFilter")?.value?.trim() || "";
    if (q) params.set("q", q);
    if (country) params.set("country_code", country.toUpperCase());
    params.set("limit", "200");
    return params.toString();
}

async function loadTaxRates() {
    const tbody = document.getElementById("taxRatesTableBody");
    if (!tbody) return;
    tbody.innerHTML =
        '<tr><td colspan="6" class="text-muted text-center py-4">Loading...</td></tr>';
    try {
        const qs = currentTaxRateQuery();
        const res = await hsCodeTaxApi(
            "GET",
            "/hs-code-tax" + (qs ? "?" + qs : ""),
        );
        const rows = res.data || [];
        if (!rows.length) {
            tbody.innerHTML =
                '<tr><td colspan="6" class="text-muted text-center py-4">No tax rates found.</td></tr>';
            return;
        }
        tbody.innerHTML = rows
            .map(
                (row) => `
          <tr>
            <td><code>${escapeHsHtml(row.hs_code)}</code></td>
            <td>${escapeHsHtml(row.country_code)}</td>
            <td>${formatHsAmount(row.rate_percent)}</td>
            <td>${escapeHsHtml(row.effective_from || "Current")}</td>
            <td>${escapeHsHtml(row.notes || "—")}</td>
            <td class="text-end">
              <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="editTaxRate(${row.id})">Edit</button>
              <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteTaxRate(${row.id})">Delete</button>
            </td>
          </tr>`,
            )
            .join("");
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-danger text-center py-4">${escapeHsHtml(error.message)}</td></tr>`;
    }
}

function debounceTaxRateSearch() {
    clearTimeout(hsCodeTaxSearchTimer);
    hsCodeTaxSearchTimer = setTimeout(loadTaxRates, 200);
}

window.clearTaxRateFilters = function () {
    document.getElementById("taxRateSearch").value = "";
    document.getElementById("taxRateCountryFilter").value = "LB";
    loadTaxRates();
};

window.openTaxRateForm = function (rate = null) {
    document.getElementById("taxRateForm").reset();
    document.getElementById("taxRateId").value = rate?.id || "";
    document.getElementById("taxRateModalTitle").textContent = rate
        ? "Edit Tax Rate"
        : "Add Tax Rate";
    document.getElementById("taxRateHsCode").value = rate?.hs_code || "";
    document.getElementById("taxRateCountryCode").value =
        rate?.country_code || "LB";
    document.getElementById("taxRatePercent").value = rate?.rate_percent ?? "";
    document.getElementById("taxRateEffectiveFrom").value =
        rate?.effective_from || "";
    document.getElementById("taxRateNotes").value = rate?.notes || "";
    bootstrap.Modal.getOrCreateInstance(
        document.getElementById("taxRateModal"),
    ).show();
};

window.editTaxRate = async function (id) {
    try {
        const res = await hsCodeTaxApi("GET", "/hs-code-tax/" + id);
        window.openTaxRateForm(res.data);
    } catch (error) {
        hsCodeTaxNotify(error.message, "danger");
    }
};

window.saveTaxRate = async function () {
    const id = document.getElementById("taxRateId").value;
    const payload = {
        hs_code: document.getElementById("taxRateHsCode").value.trim(),
        country_code: document
            .getElementById("taxRateCountryCode")
            .value.trim()
            .toUpperCase(),
        rate_percent: document.getElementById("taxRatePercent").value.trim(),
        effective_from:
            document.getElementById("taxRateEffectiveFrom").value || null,
        notes: document.getElementById("taxRateNotes").value.trim() || null,
    };
    if (
        !payload.hs_code ||
        !payload.country_code ||
        payload.rate_percent === ""
    ) {
        hsCodeTaxNotify("HS code, country, and rate are required.", "danger");
        return;
    }
    try {
        await hsCodeTaxApi(
            id ? "PUT" : "POST",
            id ? "/hs-code-tax/" + id : "/hs-code-tax",
            payload,
        );
        bootstrap.Modal.getOrCreateInstance(
            document.getElementById("taxRateModal"),
        ).hide();
        hsCodeTaxNotify(id ? "Tax rate updated" : "Tax rate created");
        loadTaxRates();
    } catch (error) {
        hsCodeTaxNotify(error.message, "danger");
    }
};

window.deleteTaxRate = async function (id) {
    if (!window.confirm("Delete this tax rate?")) return;
    try {
        await hsCodeTaxApi("DELETE", "/hs-code-tax/" + id);
        hsCodeTaxNotify("Tax rate deleted");
        loadTaxRates();
    } catch (error) {
        hsCodeTaxNotify(error.message, "danger");
    }
};

window.toggleEstimateContext = function () {
    const context =
        document.getElementById("estimateContextType")?.value || "hs_code";
    document.querySelectorAll(".estimate-context").forEach((el) => {
        el.classList.toggle("d-none", el.dataset.context !== context);
    });
};

function buildEstimateQuery() {
    const params = new URLSearchParams();
    const country =
        document.getElementById("estimateCountryCode")?.value?.trim() || "LB";
    const valuationMode =
        document.getElementById("estimateValuationMode")?.value || "auto";
    const context =
        document.getElementById("estimateContextType")?.value || "hs_code";
    const declaredValue =
        document.getElementById("estimateDeclaredValue")?.value?.trim() || "";
    params.set("country_code", country.toUpperCase());
    params.set("valuation_mode", valuationMode);
    if (declaredValue !== "") params.set("declared_value", declaredValue);
    if (context === "hs_code") {
        const hsCode = document.getElementById("estimateHsCode").value.trim();
        if (!hsCode) throw new Error("HS code is required.");
        params.set("hs_code", hsCode);
    } else if (context === "product") {
        const productId = document.getElementById("estimateProductId").value;
        if (!productId) throw new Error("Select a product first.");
        params.set("product_id", productId);
    } else if (context === "order") {
        const orderId = document.getElementById("estimateOrderId").value.trim();
        if (!orderId) throw new Error("Order ID is required.");
        params.set("order_id", orderId);
    } else if (context === "container") {
        const containerId = document
            .getElementById("estimateContainerId")
            .value.trim();
        if (!containerId) throw new Error("Container ID is required.");
        params.set("container_id", containerId);
    }
    return params.toString();
}

function renderEstimate(data) {
    const summary = data.summary || {};
    const summaryEl = document.getElementById("estimateSummary");
    const tbody = document.getElementById("estimateResultsBody");
    summaryEl.innerHTML = `
      <div><strong>Context:</strong> ${escapeHsHtml(data.context_type || "—")} | <strong>Country:</strong> ${escapeHsHtml(data.country_code || "—")} | <strong>Valuation:</strong> ${escapeHsHtml(data.valuation_mode || "auto")}</div>
      <div class="mt-1"><strong>Lines:</strong> ${summary.line_count || 0} | <strong>Matched:</strong> ${summary.matched_count || 0} | <strong>Unmatched:</strong> ${summary.unmatched_count || 0}</div>
      <div class="mt-1"><strong>Basis Total:</strong> ${formatHsAmount(summary.basis_value_total || 0)} | <strong>Estimated Tax Total:</strong> ${formatHsAmount(summary.estimated_tax_total || 0)}</div>
    `;
    const lines = data.lines || [];
    if (!lines.length) {
        tbody.innerHTML =
            '<tr><td colspan="5" class="text-muted text-center py-4">No estimate lines returned.</td></tr>';
        return;
    }
    tbody.innerHTML = lines
        .map(
            (line) => `
          <tr>
            <td>
              <div class="fw-semibold">${escapeHsHtml(line.description || line.reference_type || "Line")}</div>
              <div class="small text-muted">${escapeHsHtml(line.reference_type || "")}${line.order_id ? ` | Order #${escapeHsHtml(line.order_id)}` : ""}${line.product_id ? ` | Product #${escapeHsHtml(line.product_id)}` : ""}</div>
            </td>
            <td><code>${escapeHsHtml(line.hs_code || "—")}</code><div class="small text-muted">${escapeHsHtml(line.matched_rate_hs_code || "No match")}</div></td>
            <td>${formatHsAmount(line.basis_value || 0)}<div class="small text-muted">${escapeHsHtml(line.basis_source || "")}</div></td>
            <td>${formatHsAmount(line.matched_rate_percent || 0)}<div class="small text-muted">${escapeHsHtml(line.rate_match_type || "unmatched")}</div></td>
            <td>${formatHsAmount(line.estimated_tax || 0)}</td>
          </tr>`,
        )
        .join("");
}

window.runHsTaxEstimate = async function () {
    try {
        const qs = buildEstimateQuery();
        const res = await hsCodeTaxApi("GET", "/hs-code-tax/estimate?" + qs);
        renderEstimate(res.data || {});
    } catch (error) {
        hsCodeTaxNotify(error.message, "danger");
    }
};

async function loadCatalogSearch() {
    const tbody = document.getElementById("catalogTableBody");
    if (!tbody) return;
    const q = document.getElementById("catalogSearch")?.value?.trim() || "";
    if (q.length < 1) {
        setCatalogSearchSummary(
            "Search by the opening digits of an HS code or by the start of a name/category.",
        );
        tbody.innerHTML =
            '<tr><td colspan="6" class="text-muted text-center py-4">Type to search the imported tariff catalog.</td></tr>';
        return;
    }
    setCatalogSearchSummary("Searching the imported tariff catalog...");
    tbody.innerHTML =
        '<tr><td colspan="6" class="text-muted text-center py-4">Loading...</td></tr>';
    try {
        const res = await hsCodeTaxApi(
            "GET",
            "/hs-code-catalog?q=" + encodeURIComponent(q) + "&limit=500",
        );
        const rows = res.data || [];
        const meta = res.meta || {};
        const total = Number(meta.total || rows.length || 0);
        const returned = Number(meta.returned || rows.length || 0);
        const prefixNote =
            meta.match_mode === "hs_code_prefix"
                ? "Showing HS code matches from the first digits."
                : "Showing name/category matches with prefix results first.";
        if (!rows.length) {
            setCatalogSearchSummary(
                `No catalog entries found for "${q}". Import data from Admin → Configuration → HS Code Tariff Catalog if needed.`,
            );
            tbody.innerHTML =
                '<tr><td colspan="6" class="text-muted text-center py-4">No catalog entries found. Import data from Admin → Configuration → HS Code Tariff Catalog.</td></tr>';
            return;
        }
        setCatalogSearchSummary(
            meta.truncated
                ? `Showing ${returned} of ${total} matches for "${q}". ${prefixNote}`
                : `Showing ${returned} match${returned === 1 ? "" : "es"} for "${q}". ${prefixNote}`,
        );
        tbody.innerHTML = rows
            .map(
                (r) => `
          <tr>
            <td><code>${escapeHsHtml(r.hs_code)}</code></td>
            <td>${escapeHsHtml(r.name || "—")}</td>
            <td>${escapeHsHtml(r.category || "—")}</td>
            <td>${escapeHsHtml(r.tariff_rate || "—")}</td>
            <td>${escapeHsHtml(r.vat || "—")}</td>
            <td>${escapeHsHtml(r.section_name || "—")}</td>
          </tr>`,
            )
            .join("");
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-danger text-center py-4">${escapeHsHtml(error.message)}</td></tr>`;
    }
}

function debounceCatalogSearch() {
    clearTimeout(catalogSearchTimer);
    catalogSearchTimer = setTimeout(loadCatalogSearch, 220);
}

function setupHsCodeCatalogAutocomplete(inputId, extraOnSelect) {
    const el = document.getElementById(inputId);
    if (!el || typeof Autocomplete === "undefined") return;
    Autocomplete.init(el, {
        resource: "hs-code-catalog",
        searchPath: "",
        limit: 50,
        placeholder: "Start typing HS code or tariff name...",
        renderItem: (item) =>
            [item.hs_code, item.name].filter(Boolean).join(" — ") ||
            item.id ||
            "",
        displayValue: (item) => item.hs_code || item.id || "",
        onSelect: (item) => {
            el.value = item.hs_code || item.id || "";
            if (typeof extraOnSelect === "function") extraOnSelect(item);
        },
    });
}

document.addEventListener("DOMContentLoaded", () => {
    document
        .getElementById("taxRateSearch")
        ?.addEventListener("input", debounceTaxRateSearch);
    document
        .getElementById("taxRateCountryFilter")
        ?.addEventListener("input", debounceTaxRateSearch);
    if (typeof Autocomplete !== "undefined") {
        setupHsCodeCatalogAutocomplete("catalogSearch", () =>
            loadCatalogSearch(),
        );
        Autocomplete.init(document.getElementById("estimateProductSearch"), {
            resource: "products",
            placeholder: "Search product...",
            renderItem: (item) =>
                `${item.description_en || item.description_cn || "Product"}${item.hs_code ? ` (HS ${item.hs_code})` : ""}`,
            onSelect: (item) => {
                document.getElementById("estimateProductId").value = item.id;
            },
        });
        setupHsCodeCatalogAutocomplete("estimateHsCode");
        setupHsCodeCatalogAutocomplete("taxRateHsCode");
    }
    document
        .getElementById("estimateProductSearch")
        ?.addEventListener("input", () => {
            document.getElementById("estimateProductId").value = "";
        });

    toggleEstimateContext();
    loadTaxRates();
});
