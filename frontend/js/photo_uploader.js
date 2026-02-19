/**
 * Photo uploader - iPad-friendly: camera + gallery, thumbnails, optional compression
 * Usage: include before products.js, receiving.js
 */

const PHOTO_UPLOADER = {
    maxWidth: 1600,
    quality: 0.75,

    /** Resize image client-side (optional compression) */
    async compressImage(file) {
        if (!file.type.startsWith("image/") || file.type === "image/gif")
            return file;
        return new Promise((resolve) => {
            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = () => {
                URL.revokeObjectURL(url);
                const canvas = document.createElement("canvas");
                let { width, height } = img;
                if (width > this.maxWidth) {
                    height = (height * this.maxWidth) / width;
                    width = this.maxWidth;
                }
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext("2d");
                ctx.drawImage(img, 0, 0, width, height);
                canvas.toBlob(
                    (blob) =>
                        resolve(
                            blob
                                ? new File([blob], file.name, {
                                      type: "image/jpeg",
                                  })
                                : file,
                        ),
                    "image/jpeg",
                    this.quality,
                );
            };
            img.onerror = () => resolve(file);
            img.src = url;
        });
    },

    /** Open file picker - supports camera + gallery on iPad (no capture = user chooses) */
    pickPhotos(inputEl, opts = {}) {
        if (!inputEl) return;
        inputEl.setAttribute("accept", "image/*");
        if (opts.capture) inputEl.setAttribute("capture", opts.capture);
        else inputEl.removeAttribute("capture");
        inputEl.click();
    },

    /** Upload files, return paths. Uses uploadFile from app.js. */
    async uploadPhotos(files, onProgress) {
        const paths = [];
        for (let i = 0; i < files.length; i++) {
            if (!files[i].type.startsWith("image/")) continue;
            const file = await this.compressImage.call(this, files[i]);
            const path = await uploadFile(file);
            if (path) paths.push(path);
            if (onProgress) onProgress(i + 1, files.length);
        }
        return paths;
    },

    /** Render thumbnails with remove button. onRemoveFnName: global fn name, receives (index) */
    previewPhotos(containerEl, paths, onRemoveFnName) {
        if (!containerEl) return;
        containerEl.innerHTML = paths
            .map(
                (path, i) => `
      <div class="position-relative d-inline-block me-1 mb-1" data-path-index="${i}">
        <img src="/cargochina/backend/${path}" class="img-thumbnail" style="max-width:80px;max-height:80px" alt="">
        <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" style="padding:0.1rem 0.3rem" data-remove-index="${i}">×</button>
      </div>`,
            )
            .join("");
        if (onRemoveFnName && typeof window[onRemoveFnName] === "function") {
            containerEl
                .querySelectorAll("[data-remove-index]")
                .forEach((btn) => {
                    btn.onclick = () =>
                        window[onRemoveFnName](
                            parseInt(btn.dataset.removeIndex, 10),
                        );
                });
        }
    },
};
