(function () {
    let modalEl = null;
    let videoEl = null;
    let canvasEl = null;
    let stream = null;
    let scanTimer = null;
    let activeApply = null;
    let lastResult = null;

    function tr(text, replacements) {
        return typeof t === "function" ? t(text, replacements) : text;
    }

    function safeText(value) {
        return String(value || "")
            .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, " ")
            .trim()
            .slice(0, 2048);
    }

    function html(value) {
        return typeof escapeHtml === "function" ? escapeHtml(value) : String(value || "");
    }

    function isWechatQr(text) {
        const normalized = text.toLowerCase();
        return /wechat|weixin|wxp:\/\/|weixin:\/\/|u\.wechat\.com|weixin\.qq\.com|payapp\.weixin\.qq\.com|wx\.tenpay\.com/.test(normalized);
    }

    function parseQrFields(text) {
        const result = {
            raw_content: text,
            decoded_qr_content: text,
            is_wechat: isWechatQr(text),
            account_detail: text,
            name: "",
            phone: "",
            address: "",
        };
        const lines = text.split(/\r?\n|;+/).map((line) => line.trim()).filter(Boolean);
        const pairPatterns = [
            ["name", /^(?:n|fn|name|supplier|公司|名称|姓名)[:=](.+)$/i],
            ["phone", /^(?:tel|phone|mobile|手机|電話|电话)[:=](.+)$/i],
            ["address", /^(?:adr|address|地址)[:=](.+)$/i],
        ];
        lines.forEach((line) => {
            pairPatterns.forEach(([key, pattern]) => {
                const match = line.match(pattern);
                if (match && !result[key]) {
                    result[key] = safeText(match[1]).replace(/^;+|;+$/g, "");
                }
            });
        });
        const phoneMatch = text.match(/(?:\+?\d[\d\s().-]{5,}\d)/);
        if (!result.phone && phoneMatch) {
            result.phone = safeText(phoneMatch[0]);
        }
        return result;
    }

    function setStatus(message, type = "info") {
        const node = document.getElementById("wechatQrScannerStatus");
        if (!node) return;
        node.className = `alert alert-${type} py-2 small`;
        node.textContent = message;
    }

    function stopCamera() {
        if (scanTimer) {
            cancelAnimationFrame(scanTimer);
            scanTimer = null;
        }
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        if (videoEl) {
            videoEl.srcObject = null;
        }
    }

    function ensureModal() {
        if (modalEl) return modalEl;
        modalEl = document.createElement("div");
        modalEl.className = "modal fade";
        modalEl.id = "wechatQrScannerModal";
        modalEl.tabIndex = -1;
        modalEl.innerHTML = `
          <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">${html(tr("Scan WeChat QR"))}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="${html(tr("Close"))}"></button>
              </div>
              <div class="modal-body">
                <div id="wechatQrScannerStatus" class="alert alert-info py-2 small">${html(tr("Open Camera or Upload QR Image to scan."))}</div>
                <div class="wechat-qr-video-wrap mb-3">
                  <video id="wechatQrScannerVideo" class="w-100 rounded border bg-dark d-none" playsinline muted></video>
                  <canvas id="wechatQrScannerCanvas" class="d-none"></canvas>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                  <button type="button" class="btn btn-outline-primary" id="wechatQrOpenCameraBtn">${html(tr("Open Camera"))}</button>
                  <label class="btn btn-outline-secondary mb-0">
                    ${html(tr("Upload QR Image"))}
                    <input type="file" class="d-none" id="wechatQrUploadInput" accept="image/*,.jpg,.jpeg,.png,.webp,.jfif,.gif,.bmp,.avif">
                  </label>
                  <button type="button" class="btn btn-outline-secondary" id="wechatQrRetryBtn">${html(tr("Retry"))}</button>
                </div>
                <div class="border rounded-3 p-3 d-none" id="wechatQrResultBox">
                  <div class="fw-semibold mb-1">${html(tr("QR Scanned Successfully"))}</div>
                  <pre class="small bg-light border rounded p-2 mb-2 text-break" id="wechatQrDecodedText"></pre>
                  <div class="small text-muted mb-2" id="wechatQrDuplicateNotice"></div>
                  <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-primary" id="wechatQrUseBtn">${html(tr("Use This QR"))}</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${html(tr("Cancel"))}</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        `;
        document.body.appendChild(modalEl);
        videoEl = modalEl.querySelector("#wechatQrScannerVideo");
        canvasEl = modalEl.querySelector("#wechatQrScannerCanvas");
        modalEl.addEventListener("hidden.bs.modal", stopCamera);
        modalEl.querySelector("#wechatQrOpenCameraBtn")?.addEventListener("click", startCamera);
        modalEl.querySelector("#wechatQrRetryBtn")?.addEventListener("click", () => {
            resetResult();
            startCamera();
        });
        modalEl.querySelector("#wechatQrUploadInput")?.addEventListener("change", async (event) => {
            const file = event.target.files?.[0] || null;
            event.target.value = "";
            if (file) {
                await decodeImageFile(file);
            }
        });
        modalEl.querySelector("#wechatQrUseBtn")?.addEventListener("click", useLastResult);
        modalEl.addEventListener("paste", async (event) => {
            const files = Array.from(event.clipboardData?.files || []).filter((file) => file.type.startsWith("image/"));
            if (files[0]) {
                event.preventDefault();
                await decodeImageFile(files[0]);
            }
        });
        return modalEl;
    }

    function resetResult() {
        lastResult = null;
        modalEl?.querySelector("#wechatQrResultBox")?.classList.add("d-none");
        setStatus(tr("Open Camera or Upload QR Image to scan."), "info");
    }

    async function decodeCanvas(canvas, sourceFile = null) {
        const context = canvas.getContext("2d", { willReadFrequently: true });
        const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
        let decoded = null;
        if ("BarcodeDetector" in window) {
            try {
                const detector = new BarcodeDetector({ formats: ["qr_code"] });
                const codes = await detector.detect(canvas);
                decoded = codes?.[0]?.rawValue || null;
            } catch (_) {}
        }
        if (!decoded && typeof window.jsQR === "function") {
            const qr = window.jsQR(imageData.data, canvas.width, canvas.height);
            decoded = qr?.data || null;
        }
        if (!decoded) {
            throw new Error(tr("Unable to read this QR code. Please try again or upload a clearer image."));
        }
        await handleDecodedQr(decoded, sourceFile, canvas);
    }

    function canvasToFile(canvas) {
        return new Promise((resolve) => {
            canvas.toBlob((blob) => {
                if (!blob) return resolve(null);
                resolve(new File([blob], `wechat-qr-${Date.now()}.png`, { type: "image/png" }));
            }, "image/png");
        });
    }

    async function handleDecodedQr(rawText, sourceFile, canvas) {
        const text = safeText(rawText);
        if (!text) {
            throw new Error(tr("QR code scanned, but no supplier information was found."));
        }
        const parsed = parseQrFields(text);
        if (!parsed.is_wechat) {
            stopCamera();
            setStatus(tr("QR code scanned, but no supplier information was found."), "warning");
            return;
        }
        const excludeId = activeApply?.excludeSupplierId?.() || "";
        let duplicate = null;
        try {
            const params = new URLSearchParams({ content: text });
            if (excludeId) params.set("exclude_id", excludeId);
            const res = await api("GET", "/suppliers/wechat-qr-duplicate?" + params.toString());
            duplicate = res.data?.duplicate || null;
        } catch (_) {}
        const file = sourceFile || (canvas ? await canvasToFile(canvas) : null);
        let qrImagePath = "";
        if (!duplicate && file) {
            qrImagePath = await uploadFile(file, { category: "supplier-payment-qr" });
        }
        lastResult = { ...parsed, qr_image_path: qrImagePath, duplicate };
        stopCamera();
        modalEl.querySelector("#wechatQrDecodedText").textContent = text;
        const notice = modalEl.querySelector("#wechatQrDuplicateNotice");
        if (duplicate) {
            notice.innerHTML = `${html(tr("This WeChat QR is already linked to supplier:"))} <a href="/cargochina/suppliers.php?supplier_id=${encodeURIComponent(duplicate.id)}" data-no-translate>${html(duplicate.name || "#" + duplicate.id)}</a>`;
            setStatus(tr("This QR is Already Linked"), "warning");
        } else {
            notice.textContent = tr("WeChat QR detected. Only available QR information was filled. Please complete missing supplier details manually.");
            setStatus(tr("WeChat QR Detected"), "success");
        }
        modalEl.querySelector("#wechatQrResultBox").classList.remove("d-none");
    }

    async function decodeImageFile(file) {
        resetResult();
        if (!file.type.startsWith("image/")) {
            setStatus(tr("Please upload an image file."), "danger");
            return;
        }
        try {
            const img = new Image();
            const url = URL.createObjectURL(file);
            await new Promise((resolve, reject) => {
                img.onload = resolve;
                img.onerror = reject;
                img.src = url;
            });
            canvasEl.width = img.naturalWidth;
            canvasEl.height = img.naturalHeight;
            canvasEl.getContext("2d").drawImage(img, 0, 0);
            URL.revokeObjectURL(url);
            await decodeCanvas(canvasEl, file);
        } catch (error) {
            setStatus(error.message || tr("Unable to read this QR code. Please try again or upload a clearer image."), "danger");
        }
    }

    async function startCamera() {
        resetResult();
        if (!navigator.mediaDevices?.getUserMedia) {
            setStatus(tr("Camera Permission Required"), "warning");
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" }, audio: false });
            videoEl.srcObject = stream;
            videoEl.classList.remove("d-none");
            await videoEl.play();
            setStatus(tr("Scanning..."), "info");
            scanFrame();
        } catch (_) {
            setStatus(tr("Camera Permission Required"), "warning");
        }
    }

    async function scanFrame() {
        if (!stream || !videoEl.videoWidth) {
            scanTimer = requestAnimationFrame(scanFrame);
            return;
        }
        canvasEl.width = videoEl.videoWidth;
        canvasEl.height = videoEl.videoHeight;
        canvasEl.getContext("2d").drawImage(videoEl, 0, 0);
        try {
            await decodeCanvas(canvasEl, null);
            return;
        } catch (_) {
            scanTimer = requestAnimationFrame(scanFrame);
        }
    }

    function useLastResult() {
        if (!lastResult || !activeApply?.onResult) return;
        if (lastResult.duplicate) {
            setStatus(tr("This QR is Already Linked"), "warning");
            return;
        }
        activeApply.onResult(lastResult);
        bootstrap.Modal.getInstance(modalEl)?.hide();
    }

    window.openWeChatQrScanner = function (options) {
        activeApply = options || {};
        ensureModal();
        resetResult();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    window.parseWeChatQrContent = parseQrFields;
})();
