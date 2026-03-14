(function () {
    let activeMonth = new Date();

    function pad(value) {
        return String(value).padStart(2, "0");
    }

    function monthValue(date) {
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
    }

    function monthBounds(date) {
        const start = new Date(date.getFullYear(), date.getMonth(), 1);
        const end = new Date(date.getFullYear(), date.getMonth() + 1, 0);
        return {
            start,
            end,
            startIso: `${start.getFullYear()}-${pad(start.getMonth() + 1)}-${pad(start.getDate())}`,
            endIso: `${end.getFullYear()}-${pad(end.getMonth() + 1)}-${pad(end.getDate())}`,
        };
    }

    function sameDay(dateA, dateB) {
        return (
            dateA.getFullYear() === dateB.getFullYear() &&
            dateA.getMonth() === dateB.getMonth() &&
            dateA.getDate() === dateB.getDate()
        );
    }

    function escapeLocal(value) {
        return typeof escapeHtml === "function"
            ? escapeHtml(value)
            : String(value || "");
    }

    function renderTimelineTable(rows, type) {
        if (!rows.length) {
            return '<p class="text-muted">No records in this month.</p>';
        }
        if (type === "orders") {
            return `
                <table class="table table-sm table-hover align-middle">
                  <thead><tr><th>Date</th><th>Order</th><th>Customer</th><th>Supplier</th><th>Status</th></tr></thead>
                  <tbody>
                    ${rows
                        .map(
                            (row) => `
                      <tr>
                        <td>${escapeLocal(row.expected_ready_date)}</td>
                        <td><a href="/cargochina/orders.php?id=${row.id}">#${row.id}</a></td>
                        <td>${escapeLocal(row.customer_name)}</td>
                        <td>${escapeLocal(row.supplier_name || "-")}</td>
                        <td><span class="badge ${statusBadgeClass(row.status)}">${escapeLocal(statusLabel(row.status))}</span></td>
                      </tr>`,
                        )
                        .join("")}
                  </tbody>
                </table>`;
        }
        return `
            <table class="table table-sm table-hover align-middle">
              <thead><tr><th>ETA</th><th>Container</th><th>Status</th><th>Destination</th></tr></thead>
              <tbody>
                ${rows
                    .map(
                        (row) => `
                  <tr>
                    <td>${escapeLocal(row.eta_date)}</td>
                    <td><a href="/cargochina/containers.php?id=${row.id}">${escapeLocal(row.code || `Container #${row.id}`)}</a></td>
                    <td>${escapeLocal(row.status || "-")}</td>
                    <td>${escapeLocal(row.destination || row.destination_country || "-")}</td>
                  </tr>`,
                    )
                    .join("")}
              </tbody>
            </table>`;
    }

    function renderCalendarGrid(orders, containers) {
        const grid = document.getElementById("calendarGrid");
        if (!grid) return;
        const labels = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
        const firstDay = new Date(activeMonth.getFullYear(), activeMonth.getMonth(), 1);
        const startOffset = firstDay.getDay();
        const monthStart = new Date(activeMonth.getFullYear(), activeMonth.getMonth(), 1 - startOffset);
        const today = new Date();
        const cells = [];

        labels.forEach((label) => {
            cells.push(`<div class="cal-day-header">${label}</div>`);
        });

        for (let i = 0; i < 42; i += 1) {
            const date = new Date(monthStart);
            date.setDate(monthStart.getDate() + i);
            const iso = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
            const dayOrders = orders.filter((order) => order.expected_ready_date === iso);
            const dayContainers = containers.filter((container) => container.eta_date === iso);
            const otherMonth = date.getMonth() !== activeMonth.getMonth();
            const items = [
                ...dayOrders.slice(0, 2).map(
                    (order) => `<a class="calendar-event-pill order" href="/cargochina/orders.php?id=${order.id}">#${order.id} ${escapeLocal(order.customer_name || "")}</a>`,
                ),
                ...dayContainers.slice(0, 2).map(
                    (container) => `<a class="calendar-event-pill container" href="/cargochina/containers.php?id=${container.id}">${escapeLocal(container.code || `Container #${container.id}`)}</a>`,
                ),
            ];
            const hiddenCount =
                Math.max(0, dayOrders.length - 2) + Math.max(0, dayContainers.length - 2);
            if (hiddenCount > 0) {
                items.push(`<span class="calendar-event-pill more">+${hiddenCount} more</span>`);
            }
            cells.push(`
                <div class="cal-day${otherMonth ? " other-month" : ""}${sameDay(date, today) ? " today" : ""}">
                  <div class="cal-day-num">${date.getDate()}</div>
                  <div class="calendar-card-list">${items.join("")}</div>
                </div>`);
        }

        grid.innerHTML = cells.join("");
    }

    async function loadCalendar() {
        const bounds = monthBounds(activeMonth);
        const monthInput = document.getElementById("calendarMonth");
        const monthLabel = document.getElementById("calendarMonthLabel");
        if (monthInput) monthInput.value = monthValue(activeMonth);
        if (monthLabel) {
            monthLabel.textContent = activeMonth.toLocaleString(undefined, {
                month: "long",
                year: "numeric",
            });
        }

        try {
            const [ordersRes, containersRes] = await Promise.all([
                api(
                    "GET",
                    `/orders?date_from=${bounds.startIso}&date_to=${bounds.endIso}`,
                ),
                api("GET", "/containers"),
            ]);
            const orders = (ordersRes.data || []).filter(
                (order) =>
                    order.expected_ready_date >= bounds.startIso &&
                    order.expected_ready_date <= bounds.endIso,
            );
            const containers = (containersRes.data || []).filter(
                (container) =>
                    container.eta_date &&
                    container.eta_date >= bounds.startIso &&
                    container.eta_date <= bounds.endIso,
            );

            renderCalendarGrid(orders, containers);
            document.getElementById("ordersTimeline").innerHTML = renderTimelineTable(orders, "orders");
            document.getElementById("containersTimeline").innerHTML = renderTimelineTable(containers, "containers");
        } catch (error) {
            const grid = document.getElementById("calendarGrid");
            if (grid) {
                grid.innerHTML = `<div class="alert alert-danger">${escapeLocal(error.message || "Failed to load calendar")}</div>`;
            }
            document.getElementById("ordersTimeline").innerHTML = "";
            document.getElementById("containersTimeline").innerHTML = "";
        }
    }

    document.addEventListener("DOMContentLoaded", () => {
        const monthInput = document.getElementById("calendarMonth");
        if (monthInput) {
            monthInput.value = monthValue(activeMonth);
            monthInput.addEventListener("change", () => {
                const [year, month] = monthInput.value.split("-").map(Number);
                if (year && month) {
                    activeMonth = new Date(year, month - 1, 1);
                    loadCalendar();
                }
            });
        }
        document.getElementById("calendarPrevBtn")?.addEventListener("click", () => {
            activeMonth = new Date(activeMonth.getFullYear(), activeMonth.getMonth() - 1, 1);
            loadCalendar();
        });
        document.getElementById("calendarNextBtn")?.addEventListener("click", () => {
            activeMonth = new Date(activeMonth.getFullYear(), activeMonth.getMonth() + 1, 1);
            loadCalendar();
        });
        document.getElementById("calendarRefreshBtn")?.addEventListener("click", loadCalendar);
        loadCalendar();
    });
})();
