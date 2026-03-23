import { chromium } from "playwright";
import assert from "node:assert/strict";
import { mkdirSync } from "node:fs";

const baseUrl = process.env.CLMS_BASE_URL || "http://localhost/cargochina";
const email = process.env.CLMS_EMAIL || "admin@salameh.com";
const password = process.env.CLMS_PASSWORD || "password";
const headed = process.env.CLMS_HEADED === "1";

mkdirSync("output/playwright", { recursive: true });

function log(message) {
    process.stdout.write(`${message}\n`);
}

async function expectVisible(page, selector, message) {
    await page.waitForSelector(selector, {
        state: "visible",
        timeout: 15000,
    });
    const visible = await page.locator(selector).first().isVisible();
    assert.ok(visible, message);
}

async function expectCountAtLeast(page, selector, minimum, message) {
    await page.waitForSelector(selector, {
        state: "attached",
        timeout: 15000,
    });
    const count = await page.locator(selector).count();
    assert.ok(count >= minimum, `${message} (found ${count})`);
}

async function expectAutocompleteFirstStartsWith(
    page,
    expectedPrefix,
    message,
) {
    await expectVisible(page, ".autocomplete-dropdown", message);
    const firstText =
        (
            await page
                .locator(".autocomplete-dropdown button")
                .first()
                .textContent()
        )?.trim() || "";
    assert.ok(
        firstText.startsWith(expectedPrefix),
        `${message} (first item was "${firstText}")`,
    );
}

async function openPage(page, path) {
    await page.goto(`${baseUrl}${path}`, {
        waitUntil: "domcontentloaded",
    });
}

async function login(page) {
    await openPage(page, "/login.php");
    await page.fill("#loginEmail", email);
    await page.fill("#loginPassword", password);
    await page.locator("form").evaluate((form) => form.requestSubmit());
    await page.waitForFunction(
        () => !window.location.pathname.endsWith("/login.php"),
        null,
        { timeout: 15000 },
    );
    const currentUrl = page.url();
    assert.match(
        currentUrl,
        /\/(superadmin|warehouse|buyers|admin)\//,
        `Unexpected post-login URL: ${currentUrl}`,
    );
    log("PASS login");
}

async function checkOrders(page) {
    await openPage(page, "/orders.php");
    await expectVisible(page, "text=Statuses", "Orders status filters missing");
    await expectCountAtLeast(
        page,
        ".filter-chip input[type='checkbox']",
        6,
        "Orders status chips missing",
    );
    await expectVisible(
        page,
        "#orderVisibleCount",
        "Orders overview metric missing",
    );
    await expectVisible(
        page,
        "#orderSearch",
        "Orders search box missing",
    );
    const firstCustomer = (
        (await page.locator("#ordersTable tbody tr td:nth-child(3)").first().textContent()) ||
        ""
    ).trim();
    const query = firstCustomer.slice(0, 3);
    if (query) {
        await page.fill("#orderSearch", query);
        await expectVisible(
            page,
            ".autocomplete-dropdown",
            "Orders autocomplete dropdown missing",
        );
        await page.keyboard.press("Escape");
    }
    log("PASS orders");
}

async function checkWarehouseStock(page) {
    await openPage(page, "/warehouse_stock.php");
    await expectVisible(
        page,
        "#filterCustomerSearch",
        "Warehouse stock customer search missing",
    );
    await expectVisible(
        page,
        "#filterSupplierSearch",
        "Warehouse stock supplier search missing",
    );
    await expectCountAtLeast(
        page,
        ".stock-status-filter",
        4,
        "Warehouse stock status checkboxes missing",
    );
    await expectVisible(page, "#stockTableBody", "Warehouse stock table missing");
    log("PASS warehouse_stock");
}

async function checkFinancials(page) {
    await openPage(page, "/financials.php");
    await expectVisible(page, "#profitOrderCount", "Financials profit metrics missing");
    await expectVisible(page, "#profitCustomerSearch", "Financials search missing");
    await expectCountAtLeast(
        page,
        ".profit-status-filter",
        6,
        "Financial status chips missing",
    );
    await expectVisible(
        page,
        "#profitStatusSummary",
        "Financial status summary missing",
    );
    await expectVisible(page, "#profitSummary", "Financials summary missing");
    await page.getByRole("tab", { name: "Balances" }).click();
    await expectVisible(
        page,
        "#balanceCustomerSearch",
        "Balances customer search missing",
    );
    await expectVisible(
        page,
        "#balancesSummaryText",
        "Balances summary insight missing",
    );
    await expectCountAtLeast(
        page,
        "#customerBalancesBody tr",
        1,
        "Customer balances table did not load",
    );
    log("PASS financials");
}

async function checkHsCodeCatalog(page) {
    await openPage(page, "/admin_config.php");
    await expectVisible(
        page,
        "#hsCatalogImportBtn",
        "HS catalog import button missing",
    );
    await page.getByRole("button", { name: "Import / Update" }).click();
    await page.waitForFunction(
        () => {
            const el = document.getElementById("hsCatalogImportStatus");
            return (
                el &&
                /Imported\s+\d+\s+rows\s+from\s+lebanon_customs_tariffs\.csv/i.test(
                    el.textContent || "",
                )
            );
        },
        null,
        { timeout: 30000 },
    );
    log("PASS hs_code_catalog_import");
}

async function checkContainers(page) {
    await openPage(page, "/containers.php");
    await expectVisible(
        page,
        "#containersTotalCount",
        "Containers overview metric missing",
    );
    await expectVisible(page, "#containerSearch", "Container search missing");
    await expectCountAtLeast(
        page,
        ".container-status-filter",
        5,
        "Container status chips missing",
    );
    await expectVisible(
        page,
        "#containerStatusSummary",
        "Container status summary missing",
    );
    await expectVisible(page, "#containersTbody", "Containers table missing");
    await expectCountAtLeast(
        page,
        ".js-view-container",
        1,
        "Container view action missing",
    );
    await page.locator(".js-view-container").first().click();
    await expectVisible(
        page,
        "#containerViewModal .order-info-stat-card",
        "Container totals summary missing from view modal",
    );
    await expectVisible(
        page,
        "#containerViewModal table tfoot",
        "Container totals footer missing from view modal",
    );
    await page.locator("#containerViewModal .btn-close").click();
    await page.waitForSelector("#containerViewModal", {
        state: "hidden",
        timeout: 15000,
    });
    log("PASS containers");
}

async function checkAssignContainer(page) {
    await openPage(page, "/assign_container.php");
    await expectVisible(
        page,
        "#assignEligibleCount",
        "Assign-to-container metrics missing",
    );
    await expectVisible(
        page,
        "#orderSearch",
        "Assign-to-container order search missing",
    );
    await expectVisible(
        page,
        "#targetContainerSearch",
        "Assign-to-container container search missing",
    );
    await expectVisible(
        page,
        "#containerSummaryList",
        "Assign-to-container summary panel missing",
    );
    log("PASS assign_container");
}

async function checkProcurementDrafts(page) {
    await openPage(page, "/procurement_drafts.php");
    await expectVisible(
        page,
        "#draftOrdersTable",
        "Draft orders table missing",
    );
    await page.getByRole("button", { name: "+ Draft an Order" }).click();
    await expectVisible(
        page,
        "#draftOrderCustomer",
        "Draft order customer search missing",
    );
    await expectVisible(
        page,
        "#draftOrderSections",
        "Draft order supplier sections missing",
    );
    await page.locator("#draftOrderModal .btn-close").click();
    await page.waitForSelector("#draftOrderModal", {
        state: "hidden",
        timeout: 15000,
    });
    log("PASS procurement_drafts");
}

async function checkCustomers(page) {
    await openPage(page, "/customers.php");
    await expectVisible(
        page,
        "#customerSearch",
        "Customers search field missing",
    );
    await expectVisible(
        page,
        "#customersTable",
        "Customers table missing",
    );
    await page.getByRole("button", { name: "+ Add Customer" }).click();
    await expectVisible(
        page,
        "#customerPorContainer",
        "Customer POR inputs missing",
    );
    await expectVisible(
        page,
        "#customerSaveBtn",
        "Customer save button missing",
    );
    await page.locator("#customerModal .btn-close").click();
    await page.waitForSelector("#customerModal", {
        state: "hidden",
        timeout: 15000,
    });
    const portalButtons = page.getByRole("button", { name: "Portal Link" });
    if ((await portalButtons.count()) > 0) {
        await portalButtons.first().click();
        await expectVisible(
            page,
            "#portalHistory",
            "Customer portal history missing",
        );
        await page.locator("#portalModal .btn-close").click();
        await page.waitForSelector("#portalModal", {
            state: "hidden",
            timeout: 15000,
        });
    }
    log("PASS customers");
}

async function checkSuppliers(page) {
    await openPage(page, "/suppliers.php");
    await expectVisible(
        page,
        "#supplierSearch",
        "Suppliers search field missing",
    );
    await expectVisible(
        page,
        "#suppliersTable",
        "Suppliers table missing",
    );
    await page.getByRole("button", { name: "+ Add Supplier" }).click();
    await expectVisible(
        page,
        "#supplierAttachmentList",
        "Supplier attachments panel missing",
    );
    await expectVisible(
        page,
        "#supplierAttachmentInput",
        "Supplier attachment input missing",
    );
    await page.locator("#supplierModal .btn-close").click();
    await page.waitForSelector("#supplierModal", {
        state: "hidden",
        timeout: 15000,
    });
    log("PASS suppliers");
}

async function checkProducts(page) {
    await openPage(page, "/products.php");
    await expectVisible(
        page,
        "#productsVisibleCount",
        "Products overview metrics missing",
    );
    await expectVisible(
        page,
        "#productSearch",
        "Products search field missing",
    );
    await expectVisible(
        page,
        "#productFilterSupplierSearch",
        "Products supplier filter missing",
    );
    await expectVisible(
        page,
        "#productFilterHsCode",
        "Products HS code filter missing",
    );
    await expectVisible(
        page,
        "#productsFilterSummary",
        "Products filter summary missing",
    );
    await page.fill("#productSearch", "Yoga");
    await expectVisible(
        page,
        ".autocomplete-dropdown",
        "Products search autocomplete missing",
    );
    await page.keyboard.press("Escape");
    await page.fill("#productFilterHsCode", "85");
    await expectAutocompleteFirstStartsWith(
        page,
        "85",
        "Products HS code catalog autocomplete missing or not prefix-ranked",
    );
    await page.keyboard.press("Escape");
    await expectVisible(page, "#productsTable", "Products table missing");
    log("PASS products");
}

async function checkHsCodeTax(page) {
    await openPage(page, "/hs_code_tax.php");
    await expectVisible(
        page,
        "#catalogSearch",
        "HS code tax catalog search missing",
    );
    await page.fill("#catalogSearch", "96");
    await expectAutocompleteFirstStartsWith(
        page,
        "96",
        "HS code tax catalog autocomplete missing or not prefix-ranked",
    );
    await page.keyboard.press("Enter");
    await page.waitForFunction(
        () => {
            const body = document.getElementById("catalogTableBody");
            return body && !/Type to search|Loading/i.test(body.textContent || "");
        },
        null,
        { timeout: 15000 },
    );
    const firstCatalogCode =
        (
            await page
                .locator("#catalogTableBody tr td code")
                .first()
                .textContent()
        )?.trim() || "";
    assert.ok(
        firstCatalogCode.startsWith("96"),
        `HS code tax catalog table did not return prefix-first results (got "${firstCatalogCode}")`,
    );
    log("PASS hs_code_tax");
}

async function checkReceiving(page) {
    await openPage(page, "/receiving.php");
    await expectVisible(
        page,
        "#receiveVisibleCount",
        "Receiving overview metric missing",
    );
    await expectCountAtLeast(
        page,
        ".receiving-status-filter",
        2,
        "Receiving status chips missing",
    );
    await expectVisible(
        page,
        "#receiveStatusSummary",
        "Receiving status summary missing",
    );
    await expectVisible(
        page,
        "#receiveFilterSummary",
        "Receiving filter summary missing",
    );
    await expectVisible(page, "#warehouseList", "Receiving list missing");
    await page.locator('a[href="#tabCalendar"]').click();
    await expectVisible(page, "#calendarGrid", "Receiving calendar missing");
    log("PASS receiving");
}

async function checkConfirmations(page) {
    await openPage(page, "/confirmations.php");
    await expectVisible(
        page,
        "#confirmQueueCount",
        "Confirmations overview metric missing",
    );
    await expectVisible(
        page,
        "#confirmFilterSummary",
        "Confirmations filter summary missing",
    );
    await expectVisible(
        page,
        "#confirmSelectionHint",
        "Confirmations selection guidance missing",
    );
    await expectVisible(page, "#confirmationsTable", "Confirmations table missing");
    log("PASS confirmations");
}

const browser = await chromium.launch({ headless: !headed });
const page = await browser.newPage({
    viewport: { width: 1440, height: 1100 },
});

try {
    await login(page);
    await checkOrders(page);
    await checkCustomers(page);
    await checkSuppliers(page);
    await checkWarehouseStock(page);
    await checkFinancials(page);
    await checkHsCodeCatalog(page);
    await checkContainers(page);
    await checkAssignContainer(page);
    await checkProcurementDrafts(page);
    await checkProducts(page);
    await checkHsCodeTax(page);
    await checkReceiving(page);
    await checkConfirmations(page);
    log("PASS ui smoke");
} catch (error) {
    try {
        await page.screenshot({
            path: "output/playwright/ui-smoke-failure.png",
            fullPage: false,
            timeout: 5000,
        });
    } catch (_) {
        // The screenshot is only a debugging aid; do not mask the real failure.
    }
    log(`FAIL ui smoke: ${error.message}`);
    await browser.close();
    process.exit(1);
}

await browser.close();
