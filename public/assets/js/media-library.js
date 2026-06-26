/**
 * CMS Media Library — Alpine.js components
 *
 * Extracted from media-library.disyl for maintainability.
 * Loaded by the media library admin template.
 *
 * @package Ikabud\CMS
 */

function cmsMediaLibrary() {
    return {
        viewMode: localStorage.getItem('cms_media_view') || 'grid',
        editorOpen: false,
        urlModalOpen: false,
        urlModalUrl: '',
        urlModalName: '',
        urlCopied: false,
        editorBusy: false,
        editorMediaId: 0,
        editorImageUrl: '',
        editorMimeType: '',
        selectedOperation: 'rotate_right',
        replaceOriginal: false,
        imageNaturalWidth: 0,
        imageNaturalHeight: 0,
        imageAspectRatio: 1,
        resizeWidth: 0,
        resizeHeight: 0,
        lockAspectRatio: true,
        zoomLevel: 1,
        historyStack: [],
        cropAspectPreset: 'free',
        cropAspectRatio: 0,
        cropOutputWidth: 0,
        cropOutputHeight: 0,
        cropX: 0,
        cropY: 0,
        cropWidth: 0,
        cropHeight: 0,
        cropDragging: false,
        cropDragMode: 'new',
        cropDragStart: null,
        cropResizeCorner: null,
        cropStartBox: null,
        init() {
            this.$watch('viewMode', value => localStorage.setItem('cms_media_view', value));
        },
        snapshotEditorState() {
            return {
                selectedOperation: this.selectedOperation,
                resizeWidth: this.resizeWidth,
                resizeHeight: this.resizeHeight,
                lockAspectRatio: this.lockAspectRatio,
                cropX: this.cropX,
                cropY: this.cropY,
                cropWidth: this.cropWidth,
                cropHeight: this.cropHeight,
                cropAspectPreset: this.cropAspectPreset,
                cropAspectRatio: this.cropAspectRatio,
                cropOutputWidth: this.cropOutputWidth,
                cropOutputHeight: this.cropOutputHeight,
                zoomLevel: this.zoomLevel,
            };
        },
        applyEditorState(state) {
            if (!state || typeof state !== 'object') return;
            this.selectedOperation = state.selectedOperation || this.selectedOperation;
            this.resizeWidth = Number(state.resizeWidth || this.resizeWidth || 0);
            this.resizeHeight = Number(state.resizeHeight || this.resizeHeight || 0);
            this.lockAspectRatio = !!state.lockAspectRatio;
            this.cropX = Math.max(0, Number(state.cropX || 0));
            this.cropY = Math.max(0, Number(state.cropY || 0));
            this.cropWidth = Math.max(1, Number(state.cropWidth || 1));
            this.cropHeight = Math.max(1, Number(state.cropHeight || 1));
            this.cropAspectPreset = state.cropAspectPreset || 'free';
            this.cropAspectRatio = Number(state.cropAspectRatio || 0);
            this.cropOutputWidth = Math.max(0, Number(state.cropOutputWidth || 0));
            this.cropOutputHeight = Math.max(0, Number(state.cropOutputHeight || 0));
            this.zoomLevel = Math.min(3, Math.max(0.5, Number(state.zoomLevel || 1)));
        },
        pushEditorHistory() {
            this.historyStack.push(this.snapshotEditorState());
            if (this.historyStack.length > 30) {
                this.historyStack.shift();
            }
        },
        undoEditorState() {
            const previous = this.historyStack.pop();
            if (!previous) return;
            this.applyEditorState(previous);
        },
        resetEditorState() {
            this.historyStack = [];
            this.selectedOperation = 'rotate_right';
            this.lockAspectRatio = true;
            this.zoomLevel = 1;
            this.cropAspectPreset = 'free';
            this.cropAspectRatio = 0;
            this.cropOutputWidth = 0;
            this.cropOutputHeight = 0;
            this.resizeWidth = this.imageNaturalWidth;
            this.resizeHeight = this.imageNaturalHeight;
            this.resetCropBox();
        },
        resetZoom() { this.zoomLevel = 1; },
        setCropAspectPreset(preset) {
            this.pushEditorHistory();
            this.cropAspectPreset = preset;
            if (preset === 'free') { this.cropAspectRatio = 0; return; }
            const [w, h] = preset.split(':').map(Number);
            if (w > 0 && h > 0) {
                this.cropAspectRatio = w / h;
                if (preset === '1200:630') { this.cropOutputWidth = 1200; this.cropOutputHeight = 630; }
                this.applyCropAspectToCurrent();
            }
        },
        applyCropAspectToCurrent() {
            if (!this.cropAspectRatio || !this.cropWidth || !this.cropHeight) return;
            let w = this.cropWidth, h = Math.round(w / this.cropAspectRatio);
            if (h > this.imageNaturalHeight - this.cropY) { h = this.imageNaturalHeight - this.cropY; w = Math.round(h * this.cropAspectRatio); }
            if (w > this.imageNaturalWidth - this.cropX) { w = this.imageNaturalWidth - this.cropX; h = Math.round(w / this.cropAspectRatio); }
            this.cropWidth = Math.max(1, w); this.cropHeight = Math.max(1, h);
        },
        setOperation(operation) {
            this.pushEditorHistory();
            this.selectedOperation = operation;
            if (operation === 'resize' && this.imageNaturalWidth && this.imageNaturalHeight) {
                this.resizeWidth = this.imageNaturalWidth;
                this.resizeHeight = this.imageNaturalHeight;
            }
            if (operation === 'crop' && this.imageNaturalWidth && this.imageNaturalHeight && (!this.cropWidth || !this.cropHeight)) {
                this.resetCropBox();
            }
        },
        openEditor(id, imageUrl, mimeType) {
            if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(mimeType)) {
                toast('This file type cannot be edited', 'error');
                return;
            }
            this.editorMediaId = Number(id || 0);
            this.editorImageUrl = imageUrl;
            this.editorMimeType = mimeType;
            this.selectedOperation = 'rotate_right';
            this.replaceOriginal = false;
            this.imageNaturalWidth = 0;
            this.imageNaturalHeight = 0;
            this.imageAspectRatio = 1;
            this.resizeWidth = 0;
            this.resizeHeight = 0;
            this.lockAspectRatio = true;
            this.zoomLevel = 1;
            this.historyStack = [];
            this.cropAspectPreset = 'free';
            this.cropAspectRatio = 0;
            this.cropOutputWidth = 0;
            this.cropOutputHeight = 0;
            this.cropX = 0;
            this.cropY = 0;
            this.cropWidth = 0;
            this.cropHeight = 0;
            this.cropDragging = false;
            this.cropDragMode = 'new';
            this.cropDragStart = null;
            this.cropResizeCorner = null;
            this.cropStartBox = null;
            this.editorOpen = true;
        },
        closeEditor() {
            if (this.editorBusy) return;
            this.cropDragging = false;
            this.cropDragMode = 'new';
            this.cropResizeCorner = null;
            this.cropStartBox = null;
            this.editorOpen = false;
        },
        openUrlModal(id, url, name) {
            this.urlModalUrl = url;
            this.urlModalName = name;
            this.urlCopied = false;
            this.urlModalOpen = true;
        },
        closeUrlModal() {
            this.urlModalOpen = false;
            this.urlCopied = false;
        },
        copyUrl() {
            const input = this.$refs.urlInput;
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);
            try {
                document.execCommand('copy');
                this.urlCopied = true;
                setTimeout(() => this.urlCopied = false, 2000);
            } catch (e) { /* text already selected */ }
        },
        handleEditorImageLoad(event) {
            const img = event.target;
            this.imageNaturalWidth = Number(img.naturalWidth || 0);
            this.imageNaturalHeight = Number(img.naturalHeight || 0);
            this.imageAspectRatio = this.imageNaturalWidth > 0 && this.imageNaturalHeight > 0 ? this.imageNaturalWidth / this.imageNaturalHeight : 1;
            this.resizeWidth = this.imageNaturalWidth;
            this.resizeHeight = this.imageNaturalHeight;
            this.resetCropBox();
        },
        resetCropBox() {
            if (!this.imageNaturalWidth || !this.imageNaturalHeight) return;
            this.cropX = 0; this.cropY = 0;
            this.cropWidth = this.imageNaturalWidth; this.cropHeight = this.imageNaturalHeight;
        },
        syncCropFromWidth() {
            this.cropWidth = Math.max(1, Number(this.cropWidth || 1));
            if (this.cropAspectRatio > 0) this.cropHeight = Math.max(1, Math.round(this.cropWidth / this.cropAspectRatio));
            if (this.cropX + this.cropWidth > this.imageNaturalWidth) this.cropWidth = Math.max(1, this.imageNaturalWidth - this.cropX);
            if (this.cropY + this.cropHeight > this.imageNaturalHeight) this.cropHeight = Math.max(1, this.imageNaturalHeight - this.cropY);
        },
        syncCropFromHeight() {
            this.cropHeight = Math.max(1, Number(this.cropHeight || 1));
            if (this.cropAspectRatio > 0) this.cropWidth = Math.max(1, Math.round(this.cropHeight * this.cropAspectRatio));
            if (this.cropX + this.cropWidth > this.imageNaturalWidth) this.cropWidth = Math.max(1, this.imageNaturalWidth - this.cropX);
            if (this.cropY + this.cropHeight > this.imageNaturalHeight) this.cropHeight = Math.max(1, this.imageNaturalHeight - this.cropY);
        },
        syncResizeFromWidth() {
            const w = Math.max(1, Number(this.resizeWidth || 0));
            this.resizeWidth = w;
            if (this.lockAspectRatio && this.imageAspectRatio > 0) this.resizeHeight = Math.max(1, Math.round(w / this.imageAspectRatio));
        },
        syncResizeFromHeight() {
            const h = Math.max(1, Number(this.resizeHeight || 0));
            this.resizeHeight = h;
            if (this.lockAspectRatio && this.imageAspectRatio > 0) this.resizeWidth = Math.max(1, Math.round(h * this.imageAspectRatio));
        },
        nudgeCrop(e) {
            if (!this.editorOpen || this.selectedOperation !== 'crop') return;
            if (this.cropWidth <= 0 || this.cropHeight <= 0) return;
            const tag = document.activeElement ? document.activeElement.tagName : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;
            e.preventDefault();
            const step = e.shiftKey ? 10 : 1, resize = e.altKey;
            if (resize) {
                if (e.key === 'ArrowRight') { const m = this.imageNaturalWidth - this.cropX; this.cropWidth = Math.min(m, this.cropWidth + step); if (this.cropAspectRatio > 0) this.cropHeight = Math.max(1, Math.round(this.cropWidth / this.cropAspectRatio)); }
                else if (e.key === 'ArrowLeft') { this.cropWidth = Math.max(1, this.cropWidth - step); if (this.cropAspectRatio > 0) this.cropHeight = Math.max(1, Math.round(this.cropWidth / this.cropAspectRatio)); }
                else if (e.key === 'ArrowDown') { const m = this.imageNaturalHeight - this.cropY; this.cropHeight = Math.min(m, this.cropHeight + step); if (this.cropAspectRatio > 0) this.cropWidth = Math.max(1, Math.round(this.cropHeight * this.cropAspectRatio)); }
                else if (e.key === 'ArrowUp') { this.cropHeight = Math.max(1, this.cropHeight - step); if (this.cropAspectRatio > 0) this.cropWidth = Math.max(1, Math.round(this.cropHeight * this.cropAspectRatio)); }
                if (this.cropX + this.cropWidth > this.imageNaturalWidth) this.cropWidth = Math.max(1, this.imageNaturalWidth - this.cropX);
                if (this.cropY + this.cropHeight > this.imageNaturalHeight) this.cropHeight = Math.max(1, this.imageNaturalHeight - this.cropY);
            } else {
                if (e.key === 'ArrowLeft') this.cropX = Math.max(0, Math.min(this.cropX - step, this.imageNaturalWidth - this.cropWidth));
                if (e.key === 'ArrowRight') this.cropX = Math.max(0, Math.min(this.cropX + step, this.imageNaturalWidth - this.cropWidth));
                if (e.key === 'ArrowUp') this.cropY = Math.max(0, Math.min(this.cropY - step, this.imageNaturalHeight - this.cropHeight));
                if (e.key === 'ArrowDown') this.cropY = Math.max(0, Math.min(this.cropY + step, this.imageNaturalHeight - this.cropHeight));
            }
        },
        getImageRect() { const img = this.$refs.editorImage; return img ? img.getBoundingClientRect() : null; },
        eventToNaturalPoint(event) {
            const r = this.getImageRect();
            if (!r || !this.imageNaturalWidth || !this.imageNaturalHeight) return null;
            return { x: Math.round((Math.min(Math.max(event.clientX - r.left, 0), r.width)) * this.imageNaturalWidth / r.width), y: Math.round((Math.min(Math.max(event.clientY - r.top, 0), r.height)) * this.imageNaturalHeight / r.height) };
        },
        startCropSelection(event) {
            if (this.selectedOperation !== 'crop') return;
            const p = this.eventToNaturalPoint(event); if (!p) return;
            this.pushEditorHistory(); this.cropDragging = true; this.cropDragMode = 'new'; this.cropDragStart = p;
            this.cropResizeCorner = null; this.cropStartBox = null;
            this.cropX = p.x; this.cropY = p.y; this.cropWidth = 1; this.cropHeight = 1;
        },
        startCropMove(event) {
            if (this.selectedOperation !== 'crop') return;
            const p = this.eventToNaturalPoint(event); if (!p) return;
            this.pushEditorHistory(); this.cropDragging = true; this.cropDragMode = 'move';
            this.cropResizeCorner = null; this.cropDragStart = p;
            this.cropStartBox = { x: this.cropX, y: this.cropY, width: this.cropWidth, height: this.cropHeight };
        },
        startCropResize(corner, event) {
            if (this.selectedOperation !== 'crop') return;
            const p = this.eventToNaturalPoint(event); if (!p) return;
            this.pushEditorHistory(); this.cropDragging = true; this.cropDragMode = 'resize';
            this.cropResizeCorner = corner; this.cropDragStart = p;
            this.cropStartBox = { x: this.cropX, y: this.cropY, width: this.cropWidth, height: this.cropHeight };
        },
        updateCropSelection(event) {
            if (!this.cropDragging || this.selectedOperation !== 'crop' || !this.cropDragStart) return;
            const p = this.eventToNaturalPoint(event); if (!p) return;

            if (this.cropDragMode === 'move' && this.cropStartBox) {
                const dx = p.x - this.cropDragStart.x, dy = p.y - this.cropDragStart.y;
                this.cropX = Math.max(0, Math.min(this.cropStartBox.x + dx, this.imageNaturalWidth - this.cropStartBox.width));
                this.cropY = Math.max(0, Math.min(this.cropStartBox.y + dy, this.imageNaturalHeight - this.cropStartBox.height));
                return;
            }

            if (this.cropDragMode === 'resize' && this.cropStartBox && this.cropResizeCorner) {
                const box = this.cropStartBox, right = box.x + box.width, bottom = box.y + box.height;
                const cx = box.x + box.width / 2, cy = box.y + box.height / 2;
                let x1 = box.x, y1 = box.y, x2 = right, y2 = bottom;
                if (this.cropResizeCorner.includes('w')) x1 = p.x;
                if (this.cropResizeCorner.includes('e')) x2 = p.x;
                if (this.cropResizeCorner.includes('n')) y1 = p.y;
                if (this.cropResizeCorner.includes('s')) y2 = p.y;
                if (this.cropResizeCorner === 'n' || this.cropResizeCorner === 's') { x1 = box.x; x2 = right; }
                if (this.cropResizeCorner === 'e' || this.cropResizeCorner === 'w') { y1 = box.y; y2 = bottom; }

                if (this.cropAspectRatio > 0) {
                    if (this.cropResizeCorner === 'e' || this.cropResizeCorner === 'w') {
                        const w = Math.max(1, Math.abs(x2 - x1)), h = Math.max(1, Math.round(w / this.cropAspectRatio));
                        y1 = Math.round(cy - h / 2); y2 = y1 + h;
                    } else if (this.cropResizeCorner === 'n' || this.cropResizeCorner === 's') {
                        const h = Math.max(1, Math.abs(y2 - y1)), w = Math.max(1, Math.round(h * this.cropAspectRatio));
                        x1 = Math.round(cx - w / 2); x2 = x1 + w;
                    } else {
                        let w = Math.max(1, Math.abs(x2 - x1)), h = Math.max(1, Math.abs(y2 - y1));
                        if (w / h > this.cropAspectRatio) h = Math.max(1, Math.round(w / this.cropAspectRatio));
                        else w = Math.max(1, Math.round(h * this.cropAspectRatio));
                        if (this.cropResizeCorner.includes('w')) { x1 = right - w; x2 = right; } else { x1 = box.x; x2 = box.x + w; }
                        if (this.cropResizeCorner.includes('n')) { y1 = bottom - h; y2 = bottom; } else { y1 = box.y; y2 = box.y + h; }
                    }
                }
                if (x1 > x2) { [x1, x2] = [x2, x1]; }
                if (y1 > y2) { [y1, y2] = [y2, y1]; }
                x1 = Math.max(0, x1); y1 = Math.max(0, y1);
                x2 = Math.min(this.imageNaturalWidth, x2); y2 = Math.min(this.imageNaturalHeight, y2);
                this.cropX = Math.max(0, Math.min(x1, this.imageNaturalWidth - 1));
                this.cropY = Math.max(0, Math.min(y1, this.imageNaturalHeight - 1));
                this.cropWidth = Math.max(1, Math.min(x2 - x1, this.imageNaturalWidth - this.cropX));
                this.cropHeight = Math.max(1, Math.min(y2 - y1, this.imageNaturalHeight - this.cropY));
                return;
            }

            const start = this.cropDragStart;
            let dx = p.x - start.x, dy = p.y - start.y;
            let w = Math.max(1, Math.abs(dx)), h = Math.max(1, Math.abs(dy));
            if (this.cropAspectRatio > 0) {
                if (h <= 1) h = Math.max(1, Math.round(w / this.cropAspectRatio));
                else if (w / h > this.cropAspectRatio) h = Math.max(1, Math.round(w / this.cropAspectRatio));
                else w = Math.max(1, Math.round(h * this.cropAspectRatio));
                dx = dx < 0 ? -w : w; dy = dy < 0 ? -h : h;
            }
            const cx = dx < 0 ? start.x - w : start.x, cy = dy < 0 ? start.y - h : start.y;
            this.cropX = Math.max(0, Math.min(cx, this.imageNaturalWidth - 1));
            this.cropY = Math.max(0, Math.min(cy, this.imageNaturalHeight - 1));
            this.cropWidth = Math.max(1, Math.min(w, this.imageNaturalWidth - this.cropX));
            this.cropHeight = Math.max(1, Math.min(h, this.imageNaturalHeight - this.cropY));
        },
        finishCropSelection() {
            this.cropDragging = false; this.cropDragMode = 'new';
            this.cropDragStart = null; this.cropResizeCorner = null; this.cropStartBox = null;
        },
        displayCropStyle() {
            const r = this.getImageRect();
            if (!r || !this.imageNaturalWidth || !this.imageNaturalHeight) return 'display:none;';
            const sx = r.width / this.imageNaturalWidth, sy = r.height / this.imageNaturalHeight;
            return 'left:' + (this.cropX * sx) + 'px;top:' + (this.cropY * sy) + 'px;width:' + (this.cropWidth * sx) + 'px;height:' + (this.cropHeight * sy) + 'px;';
        },
        canApplyEdit() {
            if (!this.editorMediaId || !this.selectedOperation) return false;
            if (this.selectedOperation === 'resize') return Number(this.resizeWidth) > 0 && Number(this.resizeHeight) > 0;
            if (this.selectedOperation === 'crop') return Number(this.cropWidth) > 0 && Number(this.cropHeight) > 0;
            return true;
        },
        async applyEdit() {
            if (!this.canApplyEdit()) return;
            const payload = { operation: this.selectedOperation, mode: this.replaceOriginal ? 'replace' : 'copy' };
            if (this.selectedOperation === 'resize') { payload.width = Number(this.resizeWidth || 0); payload.height = Number(this.resizeHeight || 0); }
            if (this.selectedOperation === 'crop') {
                payload.x = Number(this.cropX || 0); payload.y = Number(this.cropY || 0);
                payload.width = Number(this.cropWidth || 0); payload.height = Number(this.cropHeight || 0);
                payload.output_width = Number(this.cropOutputWidth || 0); payload.output_height = Number(this.cropOutputHeight || 0);
            }
            this.editorBusy = true;
            try {
                const r = await fetch(CMS_BASE + '/api/v1/cms/media/' + this.editorMediaId + '/edit', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CMS_CSRF }, body: JSON.stringify(payload),
                });
                const d = await r.json();
                if (!d.ok) { toast(d.error || 'Image edit failed', 'error'); return; }
                toast(this.replaceOriginal ? 'Image updated' : 'Edited copy created');
                window.location.reload();
            } catch (e) { toast('Network error while editing image', 'error'); }
            finally { this.editorBusy = false; }
        },
    };
}

function cmsUploader() {
    return {
        uploading: false, uploadProgress: '', dragover: false,
        async upload(event) { await this.uploadFiles(event.target.files || []); },
        async handleDrop(event) { this.dragover = false; await this.uploadFiles(event.dataTransfer.files || []); },
        async uploadFiles(files) {
            if (!files.length) return;
            this.uploading = true;
            let done = 0;
            for (const file of files) {
                this.uploadProgress = (done + 1) + '/' + files.length;
                const fd = new FormData();
                fd.append('file', file);
                fd.append('_token', CMS_CSRF);
                try {
                    const r = await fetch(CMS_BASE + '/api/v1/cms/media/upload', { method: 'POST', body: fd });
                    const d = await r.json();
                    if (!d.ok) toast(d.error || 'Upload failed', 'error');
                } catch (e) { toast('Upload error', 'error'); }
                done++;
            }
            this.uploading = false;
            window.location.reload();
        },
    };
}

async function deleteMedia(id) {
    if (!confirm('Delete this file permanently?')) return;
    try {
        const r = await fetch(CMS_BASE + '/api/v1/cms/media/' + id + '/delete', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CMS_CSRF },
        });
        const d = await r.json();
        if (d.ok) { toast('File deleted'); window.location.reload(); }
        else toast(d.error || 'Delete failed', 'error');
    } catch (e) { toast('Network error', 'error'); }
}
