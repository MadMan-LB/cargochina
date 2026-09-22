// Validate API export responses before creating a file. A JSON error is never a download.
(function () {
    const pending = new Set();
    document.addEventListener('click', async (event) => {
        const link = event.target.closest?.('a[download]');
        if (!link || event.defaultPrevented || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const url = new URL(link.href, window.location.href);
        const base = new URL(window.API_BASE || '/cargochina/api/v1', window.location.href);
        if (url.origin !== base.origin || !url.pathname.startsWith(base.pathname + '/') || !/\/export(?:[/-]|$)/.test(url.pathname)) return;
        event.preventDefault();
        if (pending.has(url.href)) return;
        pending.add(url.href);
        const previousBusy = link.getAttribute('aria-busy');
        link.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url.href, { credentials: 'same-origin' });
            const type = response.headers.get('Content-Type') || '';
            if (!response.ok || /json/i.test(type)) {
                let message = 'Download failed. Please try again.';
                try { const error = await response.json(); message = error.message || message; } catch (_) {}
                throw new Error(message);
            }
            const format = url.searchParams.get('format') || 'xlsx';
            const expected = format === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            if (!type.toLowerCase().includes(expected)) throw new Error('The server did not return the requested file. Please sign in again or retry.');
            const disposition = response.headers.get('Content-Disposition') || '';
            const utf8 = disposition.match(/filename\*=UTF-8''([^;]+)/i);
            const plain = disposition.match(/filename="?([^";]+)"?/i);
            const filename = utf8 ? decodeURIComponent(utf8[1]) : plain?.[1] || 'export.' + format;
            const objectUrl = URL.createObjectURL(await response.blob());
            try {
                const fileLink = document.createElement('a');
                fileLink.href = objectUrl; fileLink.download = filename;
                document.body.appendChild(fileLink); fileLink.click(); fileLink.remove();
            } finally { window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1500); }
        } catch (error) {
            if (typeof window.showToast === 'function') window.showToast(error.message, 'danger');
            else window.alert(error.message);
        } finally {
            pending.delete(url.href);
            if (previousBusy === null) link.removeAttribute('aria-busy');
            else link.setAttribute('aria-busy', previousBusy);
        }
    });
})();
