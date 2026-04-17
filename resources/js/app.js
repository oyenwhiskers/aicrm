const appRoot = document.querySelector('#app');

if (appRoot) {
    const apiBase = appRoot.dataset.apiBase || '/api';
    const appName = appRoot.dataset.appName || 'AI CRM Nexus';
    const page = appRoot.dataset.page || 'workspace';
    let intakeGlobalsBound = false;
    let workspaceGlobalsBound = false;
    let intakeDragDepth = 0;
    let documentStageDragDepth = 0;
    let intakeImageSequence = 0;
    let filterDebounceId = null;
    let noticeTimeoutId = null;
    let modalBodyScrollTop = 0;
    let leadStatusPollTimeoutId = null;
    let intakeBatchPollTimeoutId = null;
    let intakeAutoStartTimeoutId = null;
    let pendingLeadListRefresh = false;
    const intakeBatchStorageKey = 'aicrm:intake-batch';
    const intakeUploadMaxDimension = 1920;
    const intakeUploadCompressionThresholdBytes = 900 * 1024;
    const intakeUploadJpegQuality = 0.9;

    const state = {
        appName,
        page,
        loading: false,
        loadingLeads: false,
        loadingLeadDetail: false,
        leads: [],
        selectedLeadId: initialLeadId(),
        selectedLead: null,
        activeLeadWorkflowStage: 'documents',
        documentStageDragActive: false,
        uploadingDocuments: false,
        modalBusyMessage: '',
        notices: [],
        filters: { search: '', stage: '', date: '', recent: false },
        pagination: { total: 0, current_page: 1, last_page: 1 },
        extractedRows: [],
        extractedSummary: null,
        sourceLabel: 'image extraction',
        intakeImages: [],
        intakeBatchId: null,
        intakeBatchStatus: null,
        intakeBatchNoticeStatus: null,
        intakePerformance: null,
        intakeDragActive: false,
        selectedDocumentIds: [],
        calculationDefaults: {
            requested_amount: '',
            tenure_months: 60,
            annual_interest_rate: 8,
            max_dsr_percentage: 60,
        },
    };

    boot();

    async function boot() {
        if (state.page === 'intake') {
            bindGlobalIntakeEvents();
        }

        if (state.page === 'workspace') {
            bindGlobalWorkspaceEvents();
        }

        render();

        if (state.page === 'intake') {
            void restorePersistedIntakeBatch();
        }

        if (state.page === 'workspace') {
            await loadLeads();

            if (state.selectedLeadId) {
                await loadLead(state.selectedLeadId);
            }
        }
    }

    function initialLeadId() {
        const match = window.location.pathname.match(/^\/workspace\/leads\/(\d+)$/);
        return match ? Number(match[1]) : null;
    }

    function activeDocumentStatuses() {
        return ['queued', 'processing', 'deleting'];
    }

    function documentIsActive(document) {
        return activeDocumentStatuses().includes(String(document?.upload_status || ''));
    }

    function leadHasActiveDocumentJobs(lead = state.selectedLead) {
        if (!lead) {
            return false;
        }

        if (lead.has_processing_documents) {
            return true;
        }

        return Array.isArray(lead.documents) && lead.documents.some((document) => documentIsActive(document));
    }

    function stopLeadStatusPolling() {
        if (leadStatusPollTimeoutId) {
            window.clearTimeout(leadStatusPollTimeoutId);
            leadStatusPollTimeoutId = null;
        }
    }

    function leadStatusPollDelay() {
        const documents = state.selectedLead?.documents || [];
        const hasQueuedDocuments = documents.some((document) => String(document?.upload_status || '') === 'queued');

        return hasQueuedDocuments ? 500 : 1500;
    }

    function applyLeadStatusPayload(payload) {
        if (!state.selectedLead) {
            return;
        }

        state.selectedLead = {
            ...state.selectedLead,
            stage: payload.lead_stage,
            documents: payload.documents || [],
            extracted_data: payload.extracted_data || [],
            document_completeness: payload.document_completeness,
            has_processing_documents: Boolean(payload.has_processing_documents),
            active_job_count: payload.active_job_count || 0,
        };

        const validDocumentIds = new Set((state.selectedLead.documents || []).map((document) => Number(document.id)));
        state.selectedDocumentIds = state.selectedDocumentIds.filter((documentId) => validDocumentIds.has(Number(documentId)));

        state.activeLeadWorkflowStage = resolveWorkflowStage(state.selectedLead, state.activeLeadWorkflowStage);
    }

    async function loadLeadDocumentStatus(leadId) {
        try {
            const payload = await apiRequest(`/leads/${leadId}/documents/status`);

            if (state.selectedLeadId !== Number(leadId) || !state.selectedLead) {
                return;
            }

            applyLeadStatusPayload(payload.data);
        } catch (error) {
            pushNotice(error.message, 'error');
            stopLeadStatusPolling();
            return;
        }

        render();
        syncLeadStatusPolling();
    }

    function syncLeadStatusPolling() {
        stopLeadStatusPolling();

        if (!state.selectedLeadId || !state.selectedLead) {
            return;
        }

        const hasActiveJobs = Number(state.selectedLead.active_job_count || 0) > 0 || leadHasActiveDocumentJobs();

        if (hasActiveJobs) {
            leadStatusPollTimeoutId = window.setTimeout(() => {
                loadLeadDocumentStatus(state.selectedLeadId);
            }, leadStatusPollDelay());

            return;
        }

        if (pendingLeadListRefresh) {
            pendingLeadListRefresh = false;
            void loadLeads();
        }
    }

    function rememberModalScrollPosition() {
        const modalBody = document.querySelector('.crm-modal-body');
        if (modalBody) {
            modalBodyScrollTop = modalBody.scrollTop;
        }
    }

    function restoreModalScrollPosition() {
        const modalBody = document.querySelector('.crm-modal-body');
        if (!modalBody) {
            return;
        }

        window.requestAnimationFrame(() => {
            modalBody.scrollTop = modalBodyScrollTop;
        });
    }

    async function apiRequest(path, options = {}) {
        const response = await fetch(`${apiBase}${path}`, {
            headers: {
                Accept: 'application/json',
                ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
                ...(options.headers || {}),
            },
            ...options,
        });

        const payload = await parseResponse(response);

        if (!response.ok) {
            const message = payload?.message || firstValidationMessage(payload) || 'Request failed.';
            throw new Error(message);
        }

        return payload;
    }

    async function parseResponse(response) {
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            return response.json();
        }
        return null;
    }

    function firstValidationMessage(payload) {
        const errors = payload?.errors;
        if (!errors) {
            return null;
        }
        const first = Object.values(errors)[0];
        return Array.isArray(first) ? first[0] : null;
    }

    function pushNotice(message, tone = 'info') {
        const noticeId = Date.now();

        if (noticeTimeoutId) {
            window.clearTimeout(noticeTimeoutId);
        }

        state.notices = [{ id: noticeId, message, tone }];

        noticeTimeoutId = window.setTimeout(() => {
            state.notices = state.notices.filter((notice) => notice.id !== noticeId);
            render();
        }, 5000);

        render();
    }

    function stopIntakeBatchPolling() {
        if (intakeBatchPollTimeoutId) {
            window.clearTimeout(intakeBatchPollTimeoutId);
            intakeBatchPollTimeoutId = null;
        }
    }

    function intakeBatchPollDelay() {
        return state.intakeBatchStatus === 'queued' ? 500 : 1500;
    }

    function resetIntakeBatchState() {
        stopIntakeBatchPolling();
        state.intakeBatchId = null;
        state.intakeBatchStatus = null;
        state.intakeBatchNoticeStatus = null;
        state.intakePerformance = null;
        clearPersistedIntakeBatchState();
    }

    function intakeBatchIsTerminal(status = state.intakeBatchStatus) {
        return ['completed', 'completed_with_failures', 'failed'].includes(String(status || ''));
    }

    function intakeImageStatus(status) {
        return status === 'done'
            ? 'completed'
            : status === 'retry_pending'
                ? 'retrying'
            : status === 'processing'
                ? 'processing'
                : status === 'failed'
                    ? 'failed'
                    : 'queued';
    }

    function sourceImagesLabel(sourceImages = []) {
        return sourceImages
            .map((image) => image?.filename)
            .filter(Boolean)
            .join(', ');
    }

    function buildIntakeBatchSummary(payload) {
        const totalImages = Number(payload?.total_images || 0);
        const processedImages = Number(payload?.processed_images || 0);
        const totalRows = Number(payload?.total_rows || 0);
        const failedImages = Number(payload?.failed_images || 0);

        if (!totalImages) {
            return null;
        }

        if (payload.status === 'queued') {
            return `Queued ${totalImages} image${totalImages === 1 ? '' : 's'} for backend extraction.`;
        }

        if (payload.status === 'processing') {
            return `Processed ${processedImages} of ${totalImages} image${totalImages === 1 ? '' : 's'} and found ${totalRows} lead row${totalRows === 1 ? '' : 's'}.`;
        }

        if (payload.status === 'failed') {
            return `All ${totalImages} image${totalImages === 1 ? '' : 's'} failed during extraction.`;
        }

        if (payload.status === 'completed_with_failures') {
            return `Processed ${processedImages} of ${totalImages} image${totalImages === 1 ? '' : 's'} and found ${totalRows} lead row${totalRows === 1 ? '' : 's'}. ${failedImages} image${failedImages === 1 ? '' : 's'} failed.`;
        }

        return `Processed ${processedImages} image${processedImages === 1 ? '' : 's'} and found ${totalRows} lead row${totalRows === 1 ? '' : 's'}.`;
    }

    function applyIntakeBatchPayload(payload) {
        const batchImages = Array.isArray(payload?.images) ? payload.images : [];
        const existingImages = new Map(
            state.intakeImages.map((image, index) => [
                image.batchImageId ? `batch:${image.batchImageId}` : image.key || `index:${index}`,
                image,
            ])
        );

        state.intakeBatchId = payload?.id || null;
        state.intakeBatchStatus = payload?.status || null;
        state.intakePerformance = payload?.performance || null;
        state.intakeImages = batchImages.map((batchImage, index) => {
            const existingImage = existingImages.get(`batch:${batchImage.id}`)
                || existingImages.get(String(batchImage.client_key || ''))
                || existingImages.get(`index:${index}`)
                || null;

            return {
                id: existingImage?.id || batchImage.id,
                key: existingImage?.key || String(batchImage.client_key || `batch:${batchImage.id}`),
                name: existingImage?.name || batchImage.original_filename || `Image ${index + 1}`,
                file: existingImage?.file || null,
                method: existingImage?.method || 'saved batch',
                batchImageId: batchImage.id,
                extractionStatus: intakeImageStatus(batchImage.status),
                extractedRowCount: Number(batchImage.row_count || 0),
                extractionError: batchImage.last_error || '',
                attemptsCount: Number(batchImage.attempts_count || 0),
                claimedBy: batchImage.claimed_by || '',
                timing: batchImage.timing || null,
                pipeline: batchImage.pipeline || null,
                preprocess: batchImage.preprocess || null,
            };
        });

        state.extractedRows = (payload?.rows || []).map((row) => {
            const sourceImage = sourceImagesLabel(row.source_images || []);

            return {
                id: row.id,
                name: row.name || '',
                phone_number: row.phone_number || '',
                confidence: row.confidence || 'medium',
                notes: row.notes || '',
                source_image: sourceImage,
                source_images: row.source_images || [],
            };
        });
        state.extractedSummary = buildIntakeBatchSummary(payload);
        persistIntakeBatchState();
    }

    function notifyIntakeBatchCompletion(payload) {
        if (!intakeBatchIsTerminal(payload?.status) || state.intakeBatchNoticeStatus === payload.status) {
            return;
        }

        state.intakeBatchNoticeStatus = payload.status;

        if (payload.status === 'completed') {
            pushNotice(`Extraction complete. ${payload.total_rows} lead row${payload.total_rows === 1 ? '' : 's'} found from ${payload.total_images} image${payload.total_images === 1 ? '' : 's'}.`);
            return;
        }

        if (payload.status === 'completed_with_failures') {
            pushNotice(`Extracted ${payload.total_rows} lead row${payload.total_rows === 1 ? '' : 's'}, but ${payload.failed_images} image${payload.failed_images === 1 ? '' : 's'} failed during AI processing.`, 'error');
            return;
        }

        pushNotice('Lead extraction failed for all uploaded images.', 'error');
    }

    async function loadIntakeBatchStatus(batchId) {
        try {
            const payload = await apiRequest(`/lead-intake/batches/${batchId}`);

            if (state.intakeBatchId !== Number(batchId)) {
                return;
            }

            applyIntakeBatchPayload(payload.data);
            notifyIntakeBatchCompletion(payload.data);
        } catch (error) {
            stopIntakeBatchPolling();
            state.loading = false;
            pushNotice(error.message, 'error');
            render();
            return;
        }

        state.loading = !intakeBatchIsTerminal();
        render();
        syncIntakeBatchPolling();
    }

    function syncIntakeBatchPolling() {
        stopIntakeBatchPolling();

        if (!state.intakeBatchId || intakeBatchIsTerminal()) {
            state.loading = false;
            return;
        }

        intakeBatchPollTimeoutId = window.setTimeout(() => {
            loadIntakeBatchStatus(state.intakeBatchId);
        }, intakeBatchPollDelay());
    }

    async function extractLeadImages() {
        const uploadableImages = state.intakeImages.filter((image) => image.file instanceof File);

        if (!uploadableImages.length) {
            pushNotice('Add at least one image before starting extraction.', 'error');
            return;
        }

        state.intakeImages = uploadableImages.map((image) => ({
            ...image,
            extractionStatus: 'queued',
            extractedRowCount: 0,
            extractionError: '',
        }));
        resetIntakeBatchState();
        state.extractedRows = [];
        state.loading = true;
        state.extractedSummary = `Preparing ${state.intakeImages.length} image${state.intakeImages.length === 1 ? '' : 's'} for backend extraction...`;
        render();

        try {
            const preparedImages = await Promise.all(state.intakeImages.map((image) => prepareIntakeUploadFile(image)));
            const data = new FormData();

            state.extractedSummary = `Uploading ${preparedImages.length} image${preparedImages.length === 1 ? '' : 's'} for backend extraction...`;
            render();

            preparedImages.forEach((image) => {
                data.append('images[]', image.file);
                data.append('client_keys[]', image.key);
                data.append('image_metadata[]', JSON.stringify(image.preprocessMetadata || {}));
            });
            data.append('source', state.sourceLabel || 'image extraction');

            const payload = await apiRequest('/lead-intake/batches', {
                method: 'POST',
                body: data,
            });

            applyIntakeBatchPayload(payload.data);
            notifyIntakeBatchCompletion(payload.data);
            state.loading = !intakeBatchIsTerminal(payload.data.status);
            syncIntakeBatchPolling();
        } catch (error) {
            state.loading = false;
            pushNotice(error.message, 'error');
        }

        render();
    }

    function updateIntakeImageProgress(imageId, patch) {
        state.intakeImages = state.intakeImages.map((image) => image.id === imageId ? { ...image, ...patch } : image);
    }

    function setSourceLabel(value) {
        state.sourceLabel = value?.trim() || 'image extraction';
        persistIntakeBatchState();
    }

    function intakeStorage() {
        try {
            return window.sessionStorage;
        } catch (error) {
            return null;
        }
    }

    function persistIntakeBatchState() {
        if (state.page !== 'intake') {
            return;
        }

        const storage = intakeStorage();

        if (!storage) {
            return;
        }

        if (!state.intakeBatchId) {
            storage.removeItem(intakeBatchStorageKey);
            return;
        }

        storage.setItem(intakeBatchStorageKey, JSON.stringify({
            batchId: state.intakeBatchId,
            status: state.intakeBatchStatus,
            sourceLabel: state.sourceLabel || 'image extraction',
        }));
    }

    function clearPersistedIntakeBatchState() {
        intakeStorage()?.removeItem(intakeBatchStorageKey);
    }

    async function restorePersistedIntakeBatch() {
        const storage = intakeStorage();

        if (!storage) {
            return;
        }

        let savedBatch = null;

        try {
            savedBatch = JSON.parse(storage.getItem(intakeBatchStorageKey) || 'null');
        } catch (error) {
            storage.removeItem(intakeBatchStorageKey);
            return;
        }

        const savedBatchId = Number(savedBatch?.batchId || 0);

        if (!Number.isInteger(savedBatchId) || savedBatchId <= 0) {
            storage.removeItem(intakeBatchStorageKey);
            return;
        }

        state.intakeBatchId = savedBatchId;
        state.intakeBatchStatus = savedBatch?.status || 'queued';
        state.sourceLabel = savedBatch?.sourceLabel || state.sourceLabel;
        state.loading = !intakeBatchIsTerminal(savedBatch?.status);
        state.extractedSummary = 'Restoring the latest intake batch...';
        render();

        await loadIntakeBatchStatus(savedBatchId);
    }

    async function prepareIntakeUploadFile(image) {
        if (!(image?.file instanceof File)) {
            return image;
        }

        try {
            const prepared = await optimizeIntakeImageFile(image.file);

            return {
                ...image,
                file: prepared.file,
                preprocessMetadata: prepared.preprocessMetadata,
            };
        } catch (error) {
            return image;
        }
    }

    async function optimizeIntakeImageFile(file) {
        const { bitmap, width, height } = await readImageBitmap(file);
        const scale = Math.min(1, intakeUploadMaxDimension / Math.max(width, height));
        const targetWidth = Math.max(1, Math.round(width * scale));
        const targetHeight = Math.max(1, Math.round(height * scale));
        const needsResize = scale < 1;
        const shouldCompress = file.size >= intakeUploadCompressionThresholdBytes || file.type === 'image/png' || needsResize;
        const baseMetadata = {
            strategy: shouldCompress ? 'browser_optimized' : 'browser_passthrough',
            original: {
                name: file.name,
                type: file.type || 'application/octet-stream',
                size: file.size,
                width,
                height,
            },
            optimized: {
                name: file.name,
                type: file.type || 'application/octet-stream',
                size: file.size,
                width,
                height,
            },
            scale,
            resized: needsResize,
            compressed: false,
            prepared_at: new Date().toISOString(),
        };

        if (!shouldCompress) {
            closeIntakeImageSource(bitmap);

            return {
                file,
                preprocessMetadata: baseMetadata,
            };
        }

        const canvas = document.createElement('canvas');
        canvas.width = targetWidth;
        canvas.height = targetHeight;

        const context = canvas.getContext('2d');

        if (!context) {
            closeIntakeImageSource(bitmap);

            return {
                file,
                preprocessMetadata: baseMetadata,
            };
        }

        context.drawImage(bitmap, 0, 0, targetWidth, targetHeight);
        closeIntakeImageSource(bitmap);

        const blob = await canvasToBlob(canvas, 'image/jpeg', intakeUploadJpegQuality);

        if (!blob) {
            return {
                file,
                preprocessMetadata: baseMetadata,
            };
        }

        if (!needsResize && blob.size >= file.size * 0.95) {
            return {
                file,
                preprocessMetadata: baseMetadata,
            };
        }

        const optimizedName = file.name.replace(/\.[^.]+$/, '') + '.jpg';

        const optimizedFile = new File([blob], optimizedName, {
            type: blob.type || 'image/jpeg',
            lastModified: file.lastModified,
        });

        return {
            file: optimizedFile,
            preprocessMetadata: {
                ...baseMetadata,
                compressed: true,
                optimized: {
                    name: optimizedFile.name,
                    type: optimizedFile.type || 'image/jpeg',
                    size: optimizedFile.size,
                    width: targetWidth,
                    height: targetHeight,
                },
            },
        };
    }

    async function readImageBitmap(file) {
        if (window.createImageBitmap) {
            const bitmap = await window.createImageBitmap(file);

            return {
                bitmap,
                width: bitmap.width,
                height: bitmap.height,
            };
        }

        const image = await loadImageElement(file);

        return {
            bitmap: image,
            width: image.naturalWidth,
            height: image.naturalHeight,
        };
    }

    function closeIntakeImageSource(imageSource) {
        if (typeof imageSource?.close === 'function') {
            imageSource.close();
        }
    }

    function loadImageElement(file) {
        return new Promise((resolve, reject) => {
            const objectUrl = URL.createObjectURL(file);
            const image = new Image();

            image.onload = () => {
                URL.revokeObjectURL(objectUrl);
                resolve(image);
            };

            image.onerror = () => {
                URL.revokeObjectURL(objectUrl);
                reject(new Error('Unable to load image for intake optimization.'));
            };

            image.src = objectUrl;
        });
    }

    function canvasToBlob(canvas, type, quality) {
        return new Promise((resolve) => {
            canvas.toBlob((blob) => resolve(blob), type, quality);
        });
    }

    function scheduleAutoIntakeExtraction() {
        if (intakeAutoStartTimeoutId) {
            window.clearTimeout(intakeAutoStartTimeoutId);
        }

        intakeAutoStartTimeoutId = window.setTimeout(() => {
            intakeAutoStartTimeoutId = null;

            if (state.page !== 'intake' || state.loading || !state.intakeImages.length) {
                return;
            }

            void extractLeadImages();
        }, 150);
    }

    function queueIntakeFiles(files, captureMethod = 'upload') {
        if (state.loading) {
            pushNotice('Wait for the current extraction batch to finish before changing the queue.', 'error');
            return;
        }

        if (state.intakeBatchId && state.intakeImages.length && state.intakeImages.every((image) => !(image.file instanceof File))) {
            resetIntakeBatchState();
            state.intakeImages = [];
            state.extractedRows = [];
            state.extractedSummary = null;
        }

        const validFiles = Array.from(files || []).filter((file) => file instanceof File && file.type.startsWith('image/'));

        if (!validFiles.length) {
            pushNotice('Use image files for lead intake.', 'error');
            return;
        }

        const existingKeys = new Set(state.intakeImages.map((image) => image.key));
        const newImages = validFiles
            .map((file) => ({
                id: ++intakeImageSequence,
                key: [file.name, file.size, file.lastModified].join(':'),
                name: file.name || `clipboard-image-${Date.now()}.png`,
                file,
                method: captureMethod,
                extractionStatus: 'queued',
                extractedRowCount: 0,
                extractionError: '',
            }))
            .filter((image) => !existingKeys.has(image.key));

        if (!newImages.length) {
            pushNotice('Those images are already in the intake queue.', 'error');
            return;
        }

        state.intakeImages = [...state.intakeImages, ...newImages];
        state.intakeDragActive = false;

        if (!state.sourceLabel || state.sourceLabel === 'image extraction') {
            state.sourceLabel = captureMethod === 'paste' ? 'clipboard screenshots' : captureMethod === 'drag-drop' ? 'drag and drop images' : 'file upload';
        }

        render();
        scheduleAutoIntakeExtraction();
    }

    function clearIntakeQueue() {
        if (state.loading) {
            pushNotice('Wait for the current extraction batch to finish before clearing the queue.', 'error');
            return;
        }

        state.intakeImages = [];
        resetIntakeBatchState();
        render();
    }

    function removeQueuedImage(imageId) {
        if (state.loading) {
            pushNotice('Wait for the current extraction batch to finish before removing queued images.', 'error');
            return;
        }

        state.intakeImages = state.intakeImages.filter((image) => image.id !== imageId);
        render();
    }

    function firstImageFromList(files) {
        return Array.from(files || []).find((file) => file?.type?.startsWith('image/')) || null;
    }

    function imageFilesFromList(files) {
        return Array.from(files || []).filter((file) => file?.type?.startsWith('image/'));
    }

    function transferHasImage(dataTransfer) {
        const types = Array.from(dataTransfer?.types || []);
        const itemMatch = Array.from(dataTransfer?.items || []).some((item) => item.type?.startsWith('image/') || item.kind === 'file');
        return itemMatch || types.includes('Files') || Boolean(firstImageFromList(dataTransfer?.files || []));
    }

    function transferHasFiles(dataTransfer) {
        const types = Array.from(dataTransfer?.types || []);
        const itemMatch = Array.from(dataTransfer?.items || []).some((item) => item.kind === 'file');
        return itemMatch || types.includes('Files') || Boolean((dataTransfer?.files?.length || 0) > 0);
    }

    function firstImageFromClipboard(items) {
        return Array.from(items || [])
            .find((item) => item.type?.startsWith('image/'))
            ?.getAsFile() || null;
    }

    function bindGlobalIntakeEvents() {
        if (intakeGlobalsBound) {
            return;
        }

        intakeGlobalsBound = true;

        const preventBrowserFileDrop = (event) => {
            if (!transferHasFiles(event.dataTransfer)) {
                return;
            }

            event.preventDefault();
        };

        document.addEventListener('dragenter', preventBrowserFileDrop, true);
        document.addEventListener('dragover', preventBrowserFileDrop, true);
        document.addEventListener('drop', preventBrowserFileDrop, true);

        window.addEventListener('paste', (event) => {
            const file = firstImageFromClipboard(event.clipboardData?.items || []);

            if (!file) {
                return;
            }

            event.preventDefault();
            queueIntakeFiles([file], 'paste');
            pushNotice('Screenshot pasted into the intake queue.');
        });

        document.addEventListener('dragenter', (event) => {
            if (!transferHasFiles(event.dataTransfer)) {
                return;
            }

            event.preventDefault();
            intakeDragDepth += 1;

            if (!state.intakeDragActive) {
                state.intakeDragActive = true;
                render();
            }
        });

        document.addEventListener('dragover', (event) => {
            if (!transferHasFiles(event.dataTransfer)) {
                return;
            }

            event.preventDefault();

            if (!state.intakeDragActive) {
                state.intakeDragActive = true;
                render();
            }
        });

        document.addEventListener('dragleave', (event) => {
            if (!transferHasFiles(event.dataTransfer)) {
                return;
            }

            event.preventDefault();
            intakeDragDepth = Math.max(0, intakeDragDepth - 1);

            if (intakeDragDepth === 0 && state.intakeDragActive) {
                state.intakeDragActive = false;
                render();
            }
        });

        document.addEventListener('drop', (event) => {
            if (!transferHasFiles(event.dataTransfer)) {
                return;
            }

            event.preventDefault();

            const files = imageFilesFromList(event.dataTransfer?.files || []);
            intakeDragDepth = 0;
            state.intakeDragActive = false;

            if (!files.length) {
                render();
                pushNotice('Only image files are supported for intake.', 'error');
                return;
            }

            queueIntakeFiles(files, 'drag-drop');
            pushNotice(`${files.length} image${files.length === 1 ? '' : 's'} added from drag and drop.`);
        });
    }

    function updateExtractedRow(index, field, value) {
        state.extractedRows = state.extractedRows.map((row, rowIndex) => rowIndex === index ? { ...row, [field]: value } : row);
        render();
    }

    function removeExtractedRow(index) {
        state.extractedRows = state.extractedRows.filter((_, rowIndex) => rowIndex !== index);
        render();
    }

    async function importExtractedRows() {
        const rows = state.extractedRows
            .map((row) => ({
                name: (row.name || '').trim(),
                phone_number: (row.phone_number || '').trim(),
                source: row.source_image ? `${state.sourceLabel || 'image extraction'} · ${row.source_image}` : (state.sourceLabel || 'image extraction'),
            }))
            .filter((row) => row.name && row.phone_number);

        if (!rows.length) {
            pushNotice('There are no valid rows to import. Check the extracted names and phone numbers.', 'error');
            return;
        }

        state.loading = true;
        render();

        try {
            const payload = await apiRequest('/leads/import', {
                method: 'POST',
                body: JSON.stringify({ rows }),
            });

            pushNotice(`Imported ${payload.data.created_count} leads. Duplicates skipped: ${payload.data.duplicate_count}.`);
            state.extractedRows = [];
            state.extractedSummary = null;
            state.intakeImages = [];
            resetIntakeBatchState();
            window.location.href = '/workspace';
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loading = false;
            render();
        }
    }

    async function loadLeads(page = state.pagination.current_page || 1) {
        state.loadingLeads = true;
        render();

        try {
            const requestedPage = Math.max(1, Number(page) || 1);
            const params = new URLSearchParams({ per_page: '10', page: String(requestedPage) });
            if (state.filters.search) params.set('search', state.filters.search);
            if (state.filters.stage) params.set('stage', state.filters.stage);
            if (state.filters.date) params.set('date', state.filters.date);
            if (state.filters.recent) params.set('recent', '1');

            const payload = await apiRequest(`/leads?${params.toString()}`);

            if ((payload.last_page || 1) < requestedPage && (payload.last_page || 1) > 0) {
                await loadLeads(payload.last_page);
                return;
            }

            state.leads = payload.data || [];
            state.pagination = {
                total: payload.total || state.leads.length,
                current_page: payload.current_page || 1,
                last_page: payload.last_page || 1,
            };
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loadingLeads = false;
            render();
        }
    }

    async function loadLead(leadId, { preserveUrl = false } = {}) {
        stopLeadStatusPolling();
        state.loadingLeadDetail = true;
        state.selectedLeadId = Number(leadId);
        render();

        try {
            const payload = await apiRequest(`/leads/${leadId}`);
            state.selectedLead = payload.data;
            state.activeLeadWorkflowStage = resolveWorkflowStage(state.selectedLead, state.activeLeadWorkflowStage);
            syncLeadStatusPolling();

            if (!preserveUrl) {
                history.replaceState({}, '', `/workspace/leads/${leadId}`);
            }
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loadingLeadDetail = false;
            render();
        }
    }

    function closeLeadModal() {
        state.selectedLeadId = null;
        state.selectedLead = null;
        state.activeLeadWorkflowStage = 'documents';
        state.documentStageDragActive = false;
        state.uploadingDocuments = false;
        state.modalBusyMessage = '';
        state.selectedDocumentIds = [];
        modalBodyScrollTop = 0;
        pendingLeadListRefresh = false;
        stopLeadStatusPolling();
        history.replaceState({}, '', '/workspace');
        render();
    }

    function setLeadWorkflowStage(stage) {
        if (!state.selectedLead) {
            return;
        }

        const allowedStages = availableWorkflowStages(state.selectedLead).map((item) => item.key);
        if (!allowedStages.includes(stage)) {
            return;
        }

        state.activeLeadWorkflowStage = stage;
        render();
    }

    async function uploadLeadDocuments(files) {
        if (!state.selectedLeadId) {
            pushNotice('Select a lead before uploading documents.', 'error');
            return;
        }

        const uploadFiles = Array.from(files || []);
        if (!uploadFiles.length) {
            return;
        }

        const data = new FormData();
        uploadFiles.forEach((file) => data.append('files[]', file));

        state.loading = true;
        state.uploadingDocuments = true;
        state.modalBusyMessage = 'Uploading documents and updating checklist...';
        state.documentStageDragActive = false;
        render();

        try {
            const payload = await apiRequest(`/leads/${state.selectedLeadId}/documents/batch`, {
                method: 'POST',
                body: data,
            });

            applyLeadStatusPayload(payload.data);
            pendingLeadListRefresh = true;
            pushNotice(`Queued ${payload.data.uploaded_count} document${payload.data.uploaded_count === 1 ? '' : 's'} for background processing.`);
            syncLeadStatusPolling();
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loading = false;
            state.uploadingDocuments = false;
            state.modalBusyMessage = '';
            render();
        }
    }

    async function updateDocumentAssignment(documentId, assignmentKey) {
        if (!state.selectedLeadId) {
            return;
        }

        state.loading = true;
        state.modalBusyMessage = 'Updating document assignment...';
        render();

        try {
            await apiRequest(`/leads/${state.selectedLeadId}/documents/${documentId}/assignment`, {
                method: 'PATCH',
                body: JSON.stringify({ assignment_key: assignmentKey || null }),
            });

            pushNotice('Document checklist assignment updated.');
            await loadLeadDocumentStatus(state.selectedLeadId);
            pendingLeadListRefresh = true;
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loading = false;
            state.modalBusyMessage = '';
            render();
        }
    }

    async function deleteLeadDocument(documentId) {
        if (!state.selectedLeadId) {
            return;
        }

        if (!window.confirm('Delete this uploaded document?')) {
            return;
        }

        state.loading = true;
        state.modalBusyMessage = 'Deleting document...';
        render();

        try {
            await apiRequest(`/leads/${state.selectedLeadId}/documents/${documentId}`, {
                method: 'DELETE',
            });

            pendingLeadListRefresh = true;
            pushNotice('Document queued for background deletion.');
            await loadLeadDocumentStatus(state.selectedLeadId);
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loading = false;
            state.modalBusyMessage = '';
            render();
        }
    }

    async function bulkDeleteLeadDocuments() {
        if (!state.selectedLeadId) {
            return;
        }

        const selectedIds = state.selectedDocumentIds
            .map((value) => Number(value))
            .filter((documentId) => Number.isInteger(documentId));

        if (!selectedIds.length) {
            pushNotice('Select at least one document first.', 'error');
            return;
        }

        if (!window.confirm(`Delete ${selectedIds.length} selected document${selectedIds.length === 1 ? '' : 's'}?`)) {
            return;
        }

        state.loading = true;
        state.modalBusyMessage = `Deleting ${selectedIds.length} document${selectedIds.length === 1 ? '' : 's'}...`;
        render();

        try {
            await Promise.all(
                selectedIds.map((documentId) => apiRequest(`/leads/${state.selectedLeadId}/documents/${documentId}`, {
                    method: 'DELETE',
                }))
            );

            state.selectedDocumentIds = [];
            pendingLeadListRefresh = true;
            pushNotice(`Queued ${selectedIds.length} document${selectedIds.length === 1 ? '' : 's'} for background deletion.`);
            await loadLeadDocumentStatus(state.selectedLeadId);
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loading = false;
            state.modalBusyMessage = '';
            render();
        }
    }

    function previewLeadDocument(documentId) {
        if (!state.selectedLeadId || !documentId) {
            return;
        }

        window.open(`${apiBase}/leads/${state.selectedLeadId}/documents/${documentId}/preview`, '_blank', 'noopener');
    }

    function toggleDocumentSelection(documentId, checked) {
        const normalizedId = Number(documentId);

        if (!Number.isInteger(normalizedId)) {
            return;
        }

        const next = new Set(state.selectedDocumentIds.map((value) => Number(value)).filter((value) => Number.isInteger(value)));

        if (checked) {
            next.add(normalizedId);
        } else {
            next.delete(normalizedId);
        }

        state.selectedDocumentIds = Array.from(next);
        render();
    }

    function toggleAllDocumentSelections(checked) {
        const selectableIds = (state.selectedLead?.documents || [])
            .filter((document) => !documentIsActive(document))
            .map((document) => Number(document.id));

        state.selectedDocumentIds = checked ? selectableIds : [];
        render();
    }

    async function runCalculation(form) {
        if (!state.selectedLeadId) {
            pushNotice('Select a lead before running calculation.', 'error');
            return;
        }

        const body = Object.fromEntries(new FormData(form).entries());
        Object.keys(body).forEach((key) => body[key] === '' && delete body[key]);

        state.loading = true;
        render();

        try {
            const payload = await apiRequest(`/leads/${state.selectedLeadId}/calculate`, {
                method: 'POST',
                body: JSON.stringify(body),
            });

            pushNotice(`Calculation complete. Lead stage is now ${payload.data.stage}.`);
            await loadLeads();
            await loadLead(state.selectedLeadId, { preserveUrl: true });
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loading = false;
            render();
        }
    }

    async function runBankMatch() {
        if (!state.selectedLeadId) {
            pushNotice('Select a lead before running bank matching.', 'error');
            return;
        }

        state.loading = true;
        render();

        try {
            const payload = await apiRequest(`/leads/${state.selectedLeadId}/match-banks`, {
                method: 'POST',
            });

            pushNotice(`Bank matching complete. Matches found: ${payload.data.matched_count}.`);
            await loadLeads();
            await loadLead(state.selectedLeadId, { preserveUrl: true });
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loading = false;
            render();
        }
    }

    async function deleteLead(leadId) {
        const normalizedLeadId = Number(leadId);

        if (!Number.isInteger(normalizedLeadId)) {
            return;
        }

        if (!window.confirm('Delete this lead and all related documents?')) {
            return;
        }

        state.loading = true;
        render();

        try {
            await apiRequest(`/leads/${normalizedLeadId}`, {
                method: 'DELETE',
            });

            if (state.selectedLeadId === normalizedLeadId) {
                closeLeadModal();
            }

            pushNotice('Lead deleted successfully.');
            await loadLeads();
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loading = false;
            render();
        }
    }

    function render() {
        rememberModalScrollPosition();

        appRoot.innerHTML = `
            <div class="crm-shell">
                <header class="crm-topbar">
                    <div class="crm-brand">
                        <div class="crm-brand-mark">AI</div>
                        <div>
                            <p class="crm-eyebrow">AI-Assisted Loan CRM</p>
                            <h1 class="crm-title">${escapeHtml(appName)}</h1>
                            <p class="crm-subtitle">Two-stage operator flow: extract raw leads first, then process them inside the workspace.</p>
                        </div>
                    </div>
                    <nav class="crm-inline">
                        <a class="crm-button ${state.page === 'intake' ? '' : 'crm-button--ghost'}" href="/lead-intake">Lead Intake</a>
                        <a class="crm-button ${state.page === 'workspace' ? '' : 'crm-button--ghost'}" href="/workspace">Workspace</a>
                    </nav>
                </header>

                ${renderNotices()}
                ${state.page === 'intake' ? renderIntakePage() : renderWorkspacePage()}
            </div>
        `;

        bindEvents();
        restoreModalScrollPosition();
    }

    function renderNotices() {
        if (!state.notices.length) return '';
        return `<section class="crm-status-section">${state.notices.map((notice) => `<div class="crm-status-banner" data-tone="${notice.tone === 'error' ? 'error' : 'info'}">${escapeHtml(notice.message)}</div>`).join('')}</section>`;
    }

    function renderModalBusyOverlay() {
        if (!state.modalBusyMessage) {
            return '';
        }

        return `
            <div class="crm-modal-loading-overlay" aria-live="polite" aria-busy="true">
                <span class="crm-spinner" aria-hidden="true"></span>
                <strong>${escapeHtml(state.modalBusyMessage)}</strong>
                <span>Please wait...</span>
            </div>
        `;
    }

    function renderIntakePage() {
        return `
            <section class="crm-intake-page crm-intake-layout">
                ${state.intakeDragActive ? '<div class="crm-intake-overlay"><strong>Drop image files anywhere</strong><span>YKN CRM will add them to the intake queue automatically.</span></div>' : ''}

                <section class="crm-card crm-card--solid crm-intake-header-card">
                    <div class="crm-card-body crm-intake-header-body">
                        <div>
                            <p class="crm-eyebrow">Lead Intake</p>
                            <h2 class="crm-intake-title">Add screenshots, extract rows, then review before import.</h2>
                            <p class="crm-card-note">Paste, drag, or upload image files. The page supports multiple screenshots in one batch.</p>
                        </div>
                        <div class="crm-intake-header-meta">
                            <span class="crm-badge" data-tone="stage">${state.intakeImages.length} queued</span>
                            <span class="crm-badge" data-tone="neutral">${state.extractedRows.length} extracted</span>
                        </div>
                    </div>
                </section>

                <section class="crm-card crm-card--solid crm-intake-section">
                    <div class="crm-card-head">
                        <div>
                            <h2 class="crm-card-title">Upload</h2>
                            <p class="crm-card-note">Add screenshots from clipboard, drag and drop, or file picker.</p>
                        </div>
                    </div>
                    <div class="crm-card-body">
                    <div class="crm-intake-capture crm-form-grid">
                        <input id="lead-image-input" type="file" name="image" accept="image/*" multiple hidden>
                        <button type="button" class="crm-intake-surface ${state.intakeImages.length ? 'is-ready' : ''}" data-action="pick-image" ${state.loading ? 'disabled' : ''}>
                            <span class="crm-dropzone-icon">${state.intakeImages.length ? state.intakeImages.length : '+'}</span>
                            <span class="crm-intake-surface-copy">
                                <strong>${state.intakeImages.length ? `${state.intakeImages.length} image${state.intakeImages.length === 1 ? '' : 's'} ready` : 'Click, paste, or drop screenshots anywhere'}</strong>
                                <span>${state.intakeImages.length ? 'Extraction starts automatically after new images are added.' : 'Supports screenshots, exported chats, and raw lead list images.'}</span>
                            </span>
                        </button>

                        <div class="crm-intake-queue ${state.intakeImages.length ? '' : 'is-empty'}">
                            ${state.intakeImages.length ? state.intakeImages.map((image) => `
                                <article class="crm-queue-item">
                                    <div class="crm-queue-item-main">
                                        <strong>${escapeHtml(image.name)}</strong>
                                        <span>${escapeHtml(image.method.replace('-', ' '))}</span>
                                        ${renderIntakeImageProgress(image)}
                                    </div>
                                    <button type="button" class="crm-button crm-button--ghost crm-button--small" data-remove-image="${image.id}" ${state.loading ? 'disabled' : ''}>Remove</button>
                                </article>
                            `).join('') : '<div class="crm-empty"><strong>No images queued</strong><span>Paste, drag, or upload one or more images to begin.</span></div>'}
                        </div>

                        <div class="crm-intake-footer-row">
                            <div class="crm-intake-progress">
                                ${state.loading ? `
                                    <span class="crm-spinner" aria-hidden="true"></span>
                                    <p class="crm-footer-note">Extracting image data. Please wait...</p>
                                ` : '<p class="crm-footer-note">Use clear screenshots where each row shows a name and phone number. Extraction starts automatically after upload, paste, or drop.</p>'}
                            </div>
                            <div class="crm-intake-footer-actions">
                                <button type="button" class="crm-button crm-button--ghost" data-action="clear-image" ${state.loading || !state.intakeImages.length ? 'disabled' : ''}>Reset Intake</button>
                            </div>
                        </div>
                        ${renderIntakePerformanceInsights()}
                    </div>
                    </div>
                </section>

                <section class="crm-card crm-card--solid crm-intake-section">
                    <div class="crm-card-head">
                        <div>
                            <h2 class="crm-card-title">Extracted Leads</h2>
                            <p class="crm-card-note">Review and correct the extracted names and phone numbers before importing.</p>
                        </div>
                        ${state.extractedRows.length ? `<span class="crm-badge" data-tone="stage">${state.extractedRows.length} rows</span>` : ''}
                    </div>
                    <div class="crm-card-body crm-stack">
                        ${state.extractedSummary ? `<div class="crm-inline-summary">${escapeHtml(state.extractedSummary)}</div>` : ''}
                        ${state.extractedRows.length ? `
                            <div class="crm-intake-list">
                                ${state.extractedRows.map((row, index) => `
                                    <article class="crm-intake-card">
                                        <div class="crm-intake-card-head">
                                            <div>
                                                <strong>Lead ${index + 1}</strong>
                                                ${row.source_image ? `<div class="crm-meta-text">${escapeHtml(row.source_image)}</div>` : ''}
                                            </div>
                                            <button type="button" class="crm-button crm-button--ghost crm-button--small" data-remove-row="${index}">Remove</button>
                                        </div>
                                        <div class="crm-field">
                                            <label>Name</label>
                                            <input class="crm-input" data-row-index="${index}" data-row-field="name" value="${escapeHtml(row.name || '')}">
                                        </div>
                                        <div class="crm-field">
                                            <label>Phone Number</label>
                                            <input class="crm-input" data-row-index="${index}" data-row-field="phone_number" value="${escapeHtml(row.phone_number || '')}">
                                        </div>
                                        <div class="crm-chip-row">
                                            <span class="crm-badge" data-tone="${stageTone(row.confidence)}">${escapeHtml((row.confidence || 'medium').toUpperCase())}</span>
                                            ${row.notes ? `<span class="crm-meta-text">${escapeHtml(row.notes)}</span>` : ''}
                                        </div>
                                    </article>
                                `).join('')}
                            </div>
                            <div class="crm-inline">
                                <button type="button" class="crm-button" data-action="import-extracted" ${state.loading ? 'disabled' : ''}>Create Leads From Extraction</button>
                                <a class="crm-button crm-button--ghost" href="/workspace">Go To Workspace</a>
                            </div>
                        ` : '<div class="crm-empty"><strong>No extracted rows yet.</strong><span>Paste, drag, or upload a screenshot to start intake.</span></div>'}
                    </div>
                </section>
            </section>
        `;
    }

    function renderWorkspacePage() {
        return `
            <section class="crm-workspace-page crm-stack">
                <section class="crm-card crm-card--solid">
                    <div class="crm-card-body">
                        <form id="filter-form" class="crm-filter-grid">
                            <div class="crm-field crm-filter-search">
                                <label for="search">Search</label>
                                <input class="crm-input" id="search" name="search" value="${escapeHtml(state.filters.search)}" placeholder="Search by name, phone, or IC">
                            </div>
                            <div class="crm-field">
                                <label for="stage-filter">Stage</label>
                                <select class="crm-select" id="stage-filter" name="stage">${renderStageOptions(state.filters.stage, true)}</select>
                            </div>
                            <div class="crm-field">
                                <label for="date-filter">Date Added</label>
                                <input class="crm-input" id="date-filter" name="date" type="date" value="${escapeHtml(state.filters.date)}">
                            </div>
                            <div class="crm-filter-actions">
                                <label class="crm-toggle">
                                    <input type="checkbox" id="recent-filter" name="recent" ${state.filters.recent ? 'checked' : ''}>
                                    <span>Recent 15 min</span>
                                </label>
                                <button type="button" class="crm-button crm-button--ghost" data-action="clear-filters">Clear</button>
                            </div>
                        </form>
                    </div>
                </section>

                <section class="crm-card crm-card--solid">
                    <div class="crm-card-body crm-stack">
                        ${renderLeadTable()}
                    </div>
                </section>

                ${renderLeadModal()}
            </section>
        `;
    }

    function renderIntakeImageProgress(image) {
        const status = image.extractionStatus || 'queued';
        const tone = status === 'completed'
            ? 'matched'
            : status === 'failed'
                ? 'failed'
                : status === 'retrying'
                    ? 'stage'
                : status === 'processing'
                    ? 'stage'
                    : 'neutral';
        const label = status === 'completed'
            ? `Done${image.extractedRowCount ? ` · ${image.extractedRowCount} row${image.extractedRowCount === 1 ? '' : 's'}` : ''}`
            : status === 'failed'
                ? 'Failed'
                : status === 'retrying'
                    ? 'Retrying'
                : status === 'processing'
                    ? 'Processing'
                    : 'Queued';
        const timingDetails = intakeImageTimingDetails(image);
        const pipelineSummary = renderIntakeImagePipeline(image);
        const preprocessSummary = renderIntakeImagePreprocess(image);

        return `
            <div class="crm-queue-progress">
                <span class="crm-badge" data-tone="${tone}">${escapeHtml(label)}</span>
                ${pipelineSummary}
                ${timingDetails ? `<span class="crm-meta-text">${escapeHtml(timingDetails)}</span>` : ''}
                ${preprocessSummary}
                ${(status === 'failed' || status === 'retrying') && image.extractionError ? `<span class="crm-queue-error">${escapeHtml(image.extractionError)}</span>` : ''}
            </div>
        `;
    }

    function renderIntakePerformanceInsights() {
        const performance = state.intakePerformance;

        if (!performance || (!state.intakeBatchId && !state.intakeImages.length)) {
            return '';
        }

        const items = [];
        const totalElapsed = Number(performance?.total_elapsed_seconds);
        const avgQueueWait = Number(performance?.avg_queue_wait_seconds);
        const avgAiSlotWait = Number(performance?.avg_ai_slot_wait_seconds);
        const avgAiProcessing = Number(performance?.avg_ai_processing_seconds);
        const avgAggregation = Number(performance?.avg_aggregation_seconds);
        const distinctWorkers = Number(performance?.distinct_workers);
        const imagesPerWorker = Number(performance?.images_per_worker);
        const totalImages = Number(performance?.total_images);
        const serialBatchProcessing = Boolean(performance?.serial_batch_processing);
        const imagesPerMinute = Number(performance?.images_per_minute);
        const retriedImages = Number(performance?.retried_images);
        const aiSlotWaitImages = Number(performance?.ai_slot_wait_images);
        const avgTransferSavedBytes = Number(performance?.avg_transfer_saved_bytes);
        const dominantStage = intakeInfrastructureStageLabel(performance?.dominant_stage);

        if (Number.isFinite(totalElapsed) && totalElapsed >= 0) {
            items.push({ label: 'Batch total', value: formatDurationSeconds(totalElapsed), tone: 'neutral' });
        }

        if (Number.isFinite(avgQueueWait) && avgQueueWait >= 0) {
            items.push({ label: 'Avg queue wait', value: formatDurationSeconds(avgQueueWait), tone: performance?.dominant_stage === 'queue_wait' ? 'review' : 'neutral' });
        }

        if (Number.isFinite(avgAiSlotWait) && avgAiSlotWait > 0) {
            items.push({ label: 'Avg AI slot wait', value: formatDurationSeconds(avgAiSlotWait), tone: performance?.dominant_stage === 'ai_slot_wait' ? 'review' : 'neutral' });
        }

        if (Number.isFinite(avgAiProcessing) && avgAiProcessing >= 0) {
            items.push({ label: 'Avg AI processing', value: formatDurationSeconds(avgAiProcessing), tone: performance?.dominant_stage === 'ai_processing' ? 'review' : 'neutral' });
        }

        if (Number.isFinite(avgAggregation) && avgAggregation > 0) {
            items.push({ label: 'Avg aggregation', value: formatDurationSeconds(avgAggregation), tone: performance?.dominant_stage === 'aggregation' ? 'review' : 'neutral' });
        }

        if (Number.isFinite(distinctWorkers) && distinctWorkers > 0) {
            items.push({ label: 'Workers seen', value: `${distinctWorkers}`, tone: 'stage' });
        }

        if (Number.isFinite(totalImages) && totalImages > 0) {
            items.push({ label: 'Images', value: `${totalImages}`, tone: 'neutral' });
        }

        if (Number.isFinite(imagesPerWorker) && imagesPerWorker > 0) {
            items.push({ label: 'Images/worker', value: `${imagesPerWorker}`, tone: serialBatchProcessing ? 'review' : 'stage' });
        }

        if (Number.isFinite(imagesPerMinute) && imagesPerMinute > 0) {
            items.push({ label: 'Throughput', value: `${imagesPerMinute}/min`, tone: 'stage' });
        }

        if (Number.isFinite(retriedImages) && retriedImages > 0) {
            items.push({ label: 'Retried images', value: `${retriedImages}`, tone: 'review' });
        }

        if (Number.isFinite(aiSlotWaitImages) && aiSlotWaitImages > 0) {
            items.push({ label: 'AI slot waits', value: `${aiSlotWaitImages}`, tone: 'review' });
        }

        if (Number.isFinite(avgTransferSavedBytes) && avgTransferSavedBytes > 0) {
            items.push({ label: 'Avg upload saved', value: formatBytes(avgTransferSavedBytes), tone: 'matched' });
        }

        return `
            <div class="crm-intake-performance-card">
                <div class="crm-intake-performance-head">
                    <strong>Infrastructure Insights</strong>
                    ${dominantStage ? `<span class="crm-badge" data-tone="review">Bottleneck ${escapeHtml(dominantStage)}</span>` : ''}
                </div>
                ${serialBatchProcessing ? `<span class="crm-meta-text">Serial batch processing detected: this batch used one worker for multiple images, so later images waited behind earlier ones.</span>` : ''}
                <div class="crm-intake-performance-grid">
                    ${items.map((item) => `
                        <span class="crm-badge" data-tone="${item.tone}">${escapeHtml(`${item.label} ${item.value}`)}</span>
                    `).join('')}
                </div>
                ${performance?.recommendation ? `<p class="crm-card-note">${escapeHtml(performance.recommendation)}</p>` : ''}
            </div>
        `;
    }

    function renderIntakeImagePipeline(image) {
        const pipeline = image?.pipeline || null;

        if (!pipeline) {
            return '';
        }

        const currentStageLabel = intakePipelineStageLabel(pipeline.current_stage || 'queued');
        const currentStateLabel = intakePipelineStateLabel(pipeline.current_state || 'waiting');
        const totalElapsed = Number(image?.timing?.total_elapsed_seconds);
        const totalSuffix = Number.isFinite(totalElapsed) && totalElapsed >= 0
            ? ` · Total ${formatDurationSeconds(totalElapsed)}`
            : '';
        const stages = orderedIntakePipelineStages(pipeline.stages || {});
        const chips = stages.map(([stageName, stage]) => {
            const tone = intakePipelineTone(stageName, stage?.state);
            const label = intakePipelineStageLabel(stageName);
            const elapsed = Number(stage?.elapsed_seconds);
            const suffix = Number.isFinite(elapsed) && elapsed >= 0 ? ` ${formatDurationSeconds(elapsed)}` : '';
            const current = pipeline.current_stage === stageName ? ' crm-badge--current' : '';

            return `<span class="crm-badge${current}" data-tone="${tone}">${escapeHtml(`${label}${suffix}`)}</span>`;
        }).join('');

        return `
            <div class="crm-queue-stage-block">
                <span class="crm-meta-text">Stage ${escapeHtml(currentStageLabel)} · ${escapeHtml(currentStateLabel)}${escapeHtml(totalSuffix)}</span>
                ${chips ? `<div class="crm-queue-stage-chips">${chips}</div>` : ''}
            </div>
        `;
    }

    function renderIntakeImagePreprocess(image) {
        const preprocess = image?.preprocess || null;

        if (!preprocess) {
            return '';
        }

        const details = [];
        const originalSize = Number(preprocess?.original?.size);
        const optimizedSize = Number(preprocess?.optimized?.size);
        const originalDimensions = formatImageDimensions(preprocess?.original);
        const optimizedDimensions = formatImageDimensions(preprocess?.optimized);
        const savedBytes = Number(preprocess?.transfer_saved_bytes);
        const strategy = intakePreprocessStrategyLabel(preprocess?.strategy);

        if (strategy) {
            details.push(strategy);
        }

        if (Number.isFinite(originalSize) && originalSize > 0 && Number.isFinite(optimizedSize) && optimizedSize > 0) {
            const sizeSummary = originalSize === optimizedSize
                ? `${formatBytes(originalSize)} upload`
                : `${formatBytes(originalSize)} -> ${formatBytes(optimizedSize)}`;

            details.push(sizeSummary);
        }

        if (originalDimensions && optimizedDimensions) {
            details.push(originalDimensions === optimizedDimensions
                ? originalDimensions
                : `${originalDimensions} -> ${optimizedDimensions}`);
        }

        if (Number.isFinite(savedBytes) && savedBytes > 0) {
            details.push(`${formatBytes(savedBytes)} saved`);
        }

        return details.length
            ? `<span class="crm-meta-text">Preprocess ${escapeHtml(details.join(' · '))}</span>`
            : '';
    }

    function intakeImageTimingDetails(image) {
        const details = [];
        const queueWait = Number(image?.timing?.queue_wait_seconds);
        const processingTime = Number(image?.timing?.processing_seconds);
        const totalTime = Number(image?.timing?.total_elapsed_seconds);
        const attemptsCount = Number(image?.attemptsCount || 0);
        const claimedBy = String(image?.claimedBy || '').trim();

        if (Number.isFinite(queueWait) && queueWait >= 0) {
            details.push(`Queue wait ${formatDurationSeconds(queueWait)}`);
        }

        if (Number.isFinite(processingTime) && processingTime >= 0 && image?.extractionStatus !== 'queued') {
            details.push(`Processing ${formatDurationSeconds(processingTime)}`);
        }

        if (Number.isFinite(totalTime) && totalTime >= 0 && ['completed', 'failed'].includes(String(image?.extractionStatus || ''))) {
            details.push(`Total ${formatDurationSeconds(totalTime)}`);
        }

        if (attemptsCount > 0) {
            details.push(`Attempts ${attemptsCount}`);
        }

        if (claimedBy) {
            details.push(`Worker ${claimedBy}`);
        }

        return details.join(' · ');
    }

    function formatDurationSeconds(totalSeconds) {
        const normalized = Math.max(0, Math.round(Number(totalSeconds) || 0));

        if (normalized < 60) {
            return `${normalized}s`;
        }

        const minutes = Math.floor(normalized / 60);
        const seconds = normalized % 60;

        if (minutes < 60) {
            return seconds ? `${minutes}m ${seconds}s` : `${minutes}m`;
        }

        const hours = Math.floor(minutes / 60);
        const remainingMinutes = minutes % 60;

        if (!remainingMinutes && !seconds) {
            return `${hours}h`;
        }

        if (!seconds) {
            return `${hours}h ${remainingMinutes}m`;
        }

        return `${hours}h ${remainingMinutes}m ${seconds}s`;
    }

    function orderedIntakePipelineStages(stages) {
        const order = ['queued', 'preprocess', 'waiting_for_ai_slot', 'ai_processing', 'aggregating', 'retry_pending', 'failed', 'completed'];

        return order
            .filter((stage) => Object.prototype.hasOwnProperty.call(stages || {}, stage))
            .map((stage) => [stage, stages[stage] || {}]);
    }

    function intakePipelineStageLabel(stage) {
        const labels = {
            queued: 'Queued',
            preprocess: 'Preprocess',
            waiting_for_ai_slot: 'Waiting for AI slot',
            ai_processing: 'AI processing',
            aggregating: 'Aggregating',
            retry_pending: 'Retry pending',
            failed: 'Failed',
            completed: 'Completed',
        };

        return labels[String(stage || '')] || String(stage || 'Queued').replaceAll('_', ' ');
    }

    function intakePipelineStateLabel(stateValue) {
        const labels = {
            waiting: 'waiting',
            active: 'active',
            queued: 'queued',
            completed: 'completed',
            failed: 'failed',
            retry_pending: 'retry pending',
        };

        return labels[String(stateValue || '')] || String(stateValue || '').replaceAll('_', ' ');
    }

    function intakePipelineTone(stage, stateValue) {
        if (stage === 'failed' || stateValue === 'failed') {
            return 'failed';
        }

        if (stage === 'completed' || stateValue === 'completed') {
            return 'matched';
        }

        if (stage === 'retry_pending' || stateValue === 'retry_pending') {
            return 'review';
        }

        return 'stage';
    }

    function intakePreprocessStrategyLabel(strategy) {
        const labels = {
            browser_optimized: 'browser optimized',
            browser_passthrough: 'browser passthrough',
            server_received: 'server received',
        };

        return labels[String(strategy || '')] || String(strategy || '').replaceAll('_', ' ');
    }

    function intakeInfrastructureStageLabel(stage) {
        const labels = {
            queue_wait: 'Queue wait',
            ai_slot_wait: 'AI slot wait',
            ai_processing: 'AI processing',
            aggregation: 'Aggregation',
        };

        return labels[String(stage || '')] || String(stage || '').replaceAll('_', ' ');
    }

    function formatImageDimensions(shape) {
        const width = Number(shape?.width);
        const height = Number(shape?.height);

        if (!Number.isFinite(width) || width <= 0 || !Number.isFinite(height) || height <= 0) {
            return '';
        }

        return `${width}x${height}`;
    }

    function formatBytes(value) {
        const size = Number(value);

        if (!Number.isFinite(size) || size < 0) {
            return '';
        }

        if (size < 1024) {
            return `${Math.round(size)} B`;
        }

        const units = ['KB', 'MB', 'GB'];
        let unitIndex = -1;
        let normalized = size;

        do {
            normalized /= 1024;
            unitIndex += 1;
        } while (normalized >= 1024 && unitIndex < units.length - 1);

        const precision = normalized >= 100 ? 0 : normalized >= 10 ? 1 : 2;

        return `${normalized.toFixed(precision)} ${units[unitIndex]}`;
    }

    function renderLeadTable() {
        if (state.loadingLeads) {
            return '<div class="crm-empty"><strong>Loading leads...</strong><span>The database is being refreshed.</span></div>';
        }

        if (!state.leads.length) {
            return '<div class="crm-empty"><strong>No leads found.</strong><span>Use the intake page to create leads from images first.</span></div>';
        }

        return `
            <div class="crm-table crm-table--database crm-leads-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Lead</th>
                            <th>Phone</th>
                            <th>Stage</th>
                            <th>Documents</th>
                            <th>Updated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${state.leads.map((lead) => `
                            <tr>
                                <td>
                                    <div class="crm-table-primary">${escapeHtml(lead.name)}</div>
                                    <div class="crm-meta-text">${lead.ic_number ? escapeHtml(lead.ic_number) : 'IC pending'}</div>
                                </td>
                                <td>${escapeHtml(lead.phone_number || 'N/A')}</td>
                                <td><span class="crm-badge" data-tone="${stageTone(lead.stage)}">${escapeHtml(lead.stage.replaceAll('_', ' '))}</span></td>
                                <td>${lead.documents_count ?? 0}</td>
                                <td>${formatDateTime(lead.updated_at)}</td>
                                <td>
                                    <div class="crm-inline crm-inline--center">
                                        ${renderLeadWhatsAppAction(lead.phone_number)}
                                        ${renderIconButton('view', 'View lead', `data-view-lead="${lead.id}"`)}
                                        ${renderIconButton('delete', 'Delete lead', `data-delete-lead="${lead.id}"`, 'crm-button--danger-ghost')}
                                    </div>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            ${renderLeadPagination()}
        `;
    }

    function renderLeadPagination() {
        const { total, current_page: currentPage, last_page: lastPage } = state.pagination;

        if (!total || lastPage <= 1) {
            return '';
        }

        const start = ((currentPage - 1) * 10) + 1;
        const end = Math.min(currentPage * 10, total);

        return `
            <div class="crm-pagination">
                <div class="crm-pagination-summary">Showing ${start}-${end} of ${total} leads</div>
                <div class="crm-pagination-controls">
                    <button type="button" class="crm-button crm-button--ghost crm-button--small" data-page-nav="prev" ${currentPage <= 1 ? 'disabled' : ''}>Previous</button>
                    <span class="crm-pagination-page">Page ${currentPage} of ${lastPage}</span>
                    <button type="button" class="crm-button crm-button--ghost crm-button--small" data-page-nav="next" ${currentPage >= lastPage ? 'disabled' : ''}>Next</button>
                </div>
            </div>
        `;
    }

    function renderLeadWhatsAppAction(phoneNumber) {
        const whatsappUrl = whatsappLink(phoneNumber);

        if (!whatsappUrl) {
            return renderIconButton('whatsapp', 'WhatsApp unavailable', 'disabled');
        }

        return `<a class="crm-button crm-button--ghost crm-button--small crm-button--icon" href="${escapeHtml(whatsappUrl)}" target="_blank" rel="noopener noreferrer" title="Open WhatsApp" aria-label="Open WhatsApp">${renderActionIcon('whatsapp')}</a>`;
    }

    function renderLeadModal() {
        if (!state.selectedLeadId) {
            return '';
        }

        if (state.loadingLeadDetail && !state.selectedLead) {
            return `
                <section class="crm-modal-backdrop" data-action="close-modal">
                    <div class="crm-modal-shell crm-card crm-card--solid" role="dialog" aria-modal="true" aria-label="Lead details">
                        <div class="crm-card-body">
                            <div class="crm-empty"><strong>Loading lead...</strong><span>Fetching the latest lead details.</span></div>
                        </div>
                    </div>
                </section>
            `;
        }

        return `
            <section class="crm-modal-backdrop" data-action="close-modal">
                <div class="crm-modal-shell" role="dialog" aria-modal="true" aria-label="Lead details">
                    ${renderLeadDetail()}
                </div>
            </section>
        `;
    }

    function renderLeadDetail() {
        if (!state.selectedLead) {
            return `<section class="crm-card crm-card--solid"><div class="crm-card-body"><div class="crm-empty"><strong>Lead not available</strong><span>Try closing the modal and opening the lead again.</span></div></div></section>`;
        }

        const lead = state.selectedLead;
        const latestCalculation = lead.calculation_results?.[lead.calculation_results.length - 1] || null;
        const workflowStages = availableWorkflowStages(lead);
        const activeStage = resolveWorkflowStage(lead, state.activeLeadWorkflowStage);

        return `
            <section class="crm-card crm-card--solid crm-modal-card">
                ${renderModalBusyOverlay()}
                <div class="crm-card-head crm-modal-header">
                    <div class="crm-modal-header-main">
                        <p class="crm-eyebrow">Lead Details</p>
                        <h2 class="crm-modal-title">${escapeHtml(lead.name)}</h2>
                        <div class="crm-modal-meta-row">
                            <span class="crm-badge" data-tone="${stageTone(lead.stage)}">${escapeHtml(lead.stage.replaceAll('_', ' '))}</span>
                            <span class="crm-modal-meta-pill">${escapeHtml(lead.phone_number || 'Phone unavailable')}</span>
                            ${lead.ic_number ? `<span class="crm-modal-meta-pill crm-modal-meta-pill--muted">IC ${escapeHtml(lead.ic_number)}</span>` : ''}
                        </div>
                    </div>
                    <div class="crm-modal-header-panel">
                        <div class="crm-modal-header-topbar">
                            <button type="button" class="crm-button crm-button--ghost crm-button--small" data-action="close-modal">Close</button>
                        </div>
                    </div>
                </div>
                <div class="crm-card-body crm-stack crm-modal-body">
                    <section class="crm-workflow-nav">
                        ${workflowStages.map((stage, index) => `
                            <button type="button" class="crm-workflow-tab ${activeStage === stage.key ? 'is-active' : ''} ${stage.locked ? 'is-locked' : ''}" data-workflow-stage="${stage.key}" ${stage.locked ? 'disabled' : ''}>
                                <span class="crm-workflow-step">${index + 1}</span>
                                <span>
                                    <strong>${escapeHtml(stage.label)}</strong>
                                    <small>${escapeHtml(stage.description)}</small>
                                </span>
                            </button>
                        `).join('')}
                    </section>

                    ${renderWorkflowStagePanel(lead, activeStage, latestCalculation)}
                </div>
            </section>
        `;
    }

    function renderWorkflowStagePanel(lead, activeStage, latestCalculation) {
        if (activeStage === 'calculation') {
            return renderCalculationStage(lead, latestCalculation);
        }

        if (activeStage === 'bank_match') {
            return renderBankMatchStage(lead);
        }

        return renderDocumentStage(lead);
    }

    function renderDocumentStage(lead) {
        const completenessItems = lead.document_completeness?.items || [];
        const extractedByDocumentId = new Map((lead.extracted_data || []).map((item) => [item.document_id, item]));
        const orderedDocuments = sortUploadedDocumentsForDisplay(lead.documents || []);

        return `
            <section class="crm-stack crm-document-stage" data-document-stage-dropzone>
                ${state.documentStageDragActive ? '<div class="crm-document-stage-overlay"><strong>Drop documents anywhere in this stage</strong><span>The files will upload and process automatically.</span></div>' : ''}
                <section class="crm-card crm-card--solid">
                    <div class="crm-card-head"><div><h3 class="crm-card-title">Upload Section</h3><p class="crm-card-note">Drop or select multiple files. The system will auto-detect IC, payslip, EPF, RAMCI, and CTOS, then update the checklist.</p></div><span class="crm-badge" data-tone="${lead.document_completeness?.is_complete ? 'matched' : lead.document_completeness?.has_review_items ? 'review' : 'stage'}">${lead.document_completeness?.received_required_slot_count || 0}/${lead.document_completeness?.required_document_slot_count || 0} matched</span></div>
                    <div class="crm-card-body crm-stack">
                        <div class="crm-bulk-upload" data-document-dropzone>
                            <input id="lead-document-input" type="file" accept="image/*,.pdf" multiple hidden>
                            <button type="button" class="crm-intake-surface ${state.uploadingDocuments ? 'is-ready' : ''}" data-action="pick-documents" ${state.uploadingDocuments ? 'disabled' : ''}>
                                <span class="crm-dropzone-icon">+</span>
                                <span class="crm-intake-surface-copy">
                                    <strong>${state.uploadingDocuments ? 'Uploading and processing documents...' : 'Add documents in one upload'}</strong>
                                    <span>${state.uploadingDocuments ? 'Please wait while the checklist is updated.' : 'Supports drag and drop or file picker. AI processing starts automatically after upload.'}</span>
                                </span>
                            </button>
                        </div>
                        <div class="crm-intake-progress">
                            ${state.uploadingDocuments ? `
                                <span class="crm-spinner" aria-hidden="true"></span>
                                <p class="crm-footer-note">Uploading files and running AI classification. This can take a moment.</p>
                            ` : '<p class="crm-footer-note">You can drop files anywhere in this document stage.</p>'}
                        </div>
                        <p class="crm-footer-note">Accepted files: JPG, JPEG, PNG, WEBP, and PDF.</p>
                    </div>
                </section>

                <section class="crm-card crm-card--solid">
                    <div class="crm-card-head"><div><h3 class="crm-card-title">Checklist Section</h3><p class="crm-card-note">Calculation unlocks only when every checklist item is complete and nothing is marked for review.</p></div></div>
                    <div class="crm-card-body crm-table">
                        <table class="crm-checklist-table">
                            <thead>
                                <tr>
                                    <th>Requirement</th>
                                    <th>Detected File</th>
                                    <th>Detail</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${completenessItems.map((item) => `
                                    <tr class="crm-checklist-group-row">
                                        <td colspan="5">
                                            <div class="crm-checklist-group-head">
                                                <strong>${escapeHtml(item.label)}</strong>
                                            </div>
                                        </td>
                                    </tr>
                                    ${(item.slots || []).map((slot) => renderChecklistTableRow(slot)).join('')}
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="crm-card crm-card--solid">
                    <div class="crm-card-head"><div><h3 class="crm-card-title">Uploaded Documents Section</h3><p class="crm-card-note">Review detected type, confidence, and checklist assignment. Use manual reassignment when AI gets it wrong.</p></div></div>
                    <div class="crm-card-body crm-table">
                        ${orderedDocuments.length ? `
                            ${renderUploadedDocumentBulkToolbar(orderedDocuments)}
                            <table class="crm-uploaded-documents-table">
                                <thead><tr><th><input type="checkbox" data-select-all-documents ${uploadedDocumentsSelectableCount(orderedDocuments) && state.selectedDocumentIds.length === uploadedDocumentsSelectableCount(orderedDocuments) ? 'checked' : ''} ${uploadedDocumentsSelectableCount(orderedDocuments) ? '' : 'disabled'}></th><th>File</th><th>AI Status</th><th>Checklist Assignment</th><th>Detail</th><th>Uploaded</th><th>Action</th></tr></thead>
                                <tbody>
                                    ${renderUploadedDocumentRows(orderedDocuments, extractedByDocumentId)}
                                </tbody>
                            </table>
                        ` : '<div class="crm-empty"><strong>No documents uploaded yet.</strong><span>Add files in the upload section to let the checklist start filling automatically.</span></div>'}
                    </div>
                </section>
            </section>
        `;
    }

    function renderChecklistGroupNote(item) {
        if (item.is_complete) {
            return 'Checklist group completed.';
        }

        if (item.review_count) {
            return `${item.review_count} file${item.review_count === 1 ? '' : 's'} need manual review before this group can be completed.`;
        }

        return `${item.missing_count} required file${item.missing_count === 1 ? '' : 's'} still missing.`;
    }

    function renderChecklistTableRow(slot) {
        const tone = slot.is_complete ? 'matched' : slot.needs_review ? 'review' : slot.is_missing ? 'failed' : 'stage';
        const status = slot.is_complete ? 'Complete' : slot.needs_review ? 'Needs review' : slot.is_missing ? 'Missing' : 'Pending';

        return `
            <tr class="crm-checklist-item-row">
                <td>
                    <div class="crm-checklist-indent">
                        <div class="crm-table-primary">${escapeHtml(slot.label)}</div>
                    </div>
                </td>
                <td><div class="crm-checklist-indent">${slot.document ? escapeHtml(slot.document.original_filename) : '<span class="crm-meta-text">Waiting for matching upload.</span>'}</div></td>
                <td><div class="crm-checklist-indent">${slot.detail ? escapeHtml(slot.detail) : '<span class="crm-meta-text">N/A</span>'}</div></td>
                <td><span class="crm-badge" data-tone="${tone}">${status}</span></td>
                <td>${slot.document ? renderIconButton('preview', 'Preview document', `data-preview-document="${slot.document.id}"`) : '<span class="crm-meta-text">-</span>'}</td>
            </tr>
        `;
    }

    function renderUploadedDocumentBulkToolbar(documents) {
        const selectableCount = uploadedDocumentsSelectableCount(documents);
        const selectedCount = state.selectedDocumentIds.length;
        const assignedCount = documents.filter((document) => filledAssignmentKey(document)).length;

        return `
            <div class="crm-bulk-actions">
                <div class="crm-bulk-actions-summary">
                    <p class="crm-bulk-actions-label">Bulk Actions</p>
                    <div class="crm-bulk-actions-metrics">
                        <span class="crm-bulk-metric"><strong>${selectedCount}</strong><span>selected</span></span>
                        <span class="crm-bulk-metric"><strong>${assignedCount}</strong><span>matched to checklist</span></span>
                        <span class="crm-bulk-metric"><strong>${selectableCount}</strong><span>selectable</span></span>
                    </div>
                </div>
                <div class="crm-bulk-actions-controls">
                    <button type="button" class="crm-button crm-button--ghost crm-button--small" data-select-all-toggle ${selectableCount ? '' : 'disabled'}>${selectedCount === selectableCount && selectableCount ? 'Clear Selection' : 'Select All Ready'}</button>
                    <button type="button" class="crm-button crm-button--danger crm-button--small" data-bulk-delete-documents ${selectedCount ? '' : 'disabled'}>Delete Selected</button>
                </div>
            </div>
        `;
    }

    function uploadedDocumentsSelectableCount(documents) {
        return (documents || []).filter((document) => !documentIsActive(document)).length;
    }

    function renderUploadedDocumentRows(documents, extractedByDocumentId) {
        const groups = groupUploadedDocuments(documents);

        return groups.map((group) => `
            <tr class="crm-checklist-group-row">
                <td colspan="7">
                    <div class="crm-checklist-group-head">
                        <strong>${escapeHtml(group.label)}</strong>
                    </div>
                </td>
            </tr>
            ${group.documents.map((document) => renderUploadedDocumentRow(document, extractedByDocumentId.get(document.id))).join('')}
        `).join('');
    }

    function renderUploadedDocumentRow(document, extraction) {
        const classification = document.classification || {};
        const assignmentKey = document.manual_assignment_key || document.assigned_checklist_key || inferAssignmentFromDocument(document);
        const uploadStatus = String(document.upload_status || 'uploaded');
        const isActive = documentIsActive(document);
        const isSelected = state.selectedDocumentIds.includes(Number(document.id));
        const aiStatusTone = uploadStatus === 'queued'
            ? 'stage'
            : uploadStatus === 'processing'
                ? 'stage'
                : uploadStatus === 'deleting'
                    ? 'neutral'
                    : document.manual_review_resolved
                        ? 'matched'
                        : classification.needs_review
                            ? 'review'
                            : uploadStatus === 'failed'
                                ? 'failed'
                                : 'matched';
        const aiStatusLabel = uploadStatus === 'queued'
            ? 'Queued'
            : uploadStatus === 'processing'
                ? 'Processing'
                : uploadStatus === 'deleting'
                    ? 'Deleting'
                    : uploadStatus === 'failed'
                        ? 'Processing Failed'
                        : document.manual_review_resolved
                            ? 'Manually confirmed'
                            : classification.needs_review
                                ? 'Needs review'
                                : `Detected ${String(classification.confidence || 'medium').toUpperCase()}`;
        const detail = classification.ic_side ? `IC ${classification.ic_side}` : classification.statement_period || classification.statement_year || extraction?.summary || 'No extraction summary';

        return `
            <tr class="crm-checklist-item-row">
                <td><input type="checkbox" data-document-select="${document.id}" ${isSelected ? 'checked' : ''} ${isActive ? 'disabled' : ''}></td>
                <td>
                    <div class="crm-checklist-indent">
                        <div class="crm-table-primary">${escapeHtml(document.original_filename || 'Uploaded document')}</div>
                    </div>
                </td>
                <td><span class="crm-badge crm-badge--status" data-tone="${aiStatusTone}">${escapeHtml(aiStatusLabel)}</span></td>
                <td>
                    <select class="crm-select crm-select--compact" data-document-assignment="${document.id}" ${isActive ? 'disabled' : ''}>
                        ${renderChecklistAssignmentOptions(assignmentKey)}
                    </select>
                </td>
                <td>${escapeHtml(String(detail || 'N/A'))}</td>
                <td>${formatDateTime(document.uploaded_at)}</td>
                <td>${uploadStatus === 'deleting' ? '<span class="crm-meta-text">Removing...</span>' : `<div class="crm-inline">${renderIconButton('preview', 'Preview document', `data-preview-document="${document.id}"`)}${renderIconButton('delete', 'Remove document', `data-delete-document="${document.id}" ${isActive ? 'disabled' : ''}`, 'crm-button--danger-ghost')}</div>`}</td>
            </tr>
        `;
    }

    function groupUploadedDocuments(documents) {
        const groups = [];

        for (const document of documents || []) {
            const groupKey = uploadedDocumentGroupKey(document);
            const existingGroup = groups.find((group) => group.key === groupKey);

            if (existingGroup) {
                existingGroup.documents.push(document);
                continue;
            }

            groups.push({
                key: groupKey,
                label: uploadedDocumentGroupLabel(groupKey),
                documents: [document],
            });
        }

        return groups;
    }

    function uploadedDocumentGroupKey(document) {
        const assignmentKey = filledAssignmentKey(document);

        if (assignmentKey.startsWith('ic_')) {
            return 'ic';
        }

        if (assignmentKey.startsWith('payslip_')) {
            return 'payslip';
        }

        if (assignmentKey.startsWith('epf_')) {
            return 'epf';
        }

        if (assignmentKey === 'ramci' || assignmentKey === 'ctos') {
            return assignmentKey;
        }

        const classification = document.classification || {};
        return document.effective_document_type || classification.document_type || document.document_type || 'other';
    }

    function uploadedDocumentGroupLabel(groupKey) {
        const labels = {
            ic: 'Identity Card',
            payslip: 'Payslips',
            epf: 'EPF Statements',
            ramci: 'RAMCI',
            ctos: 'CTOS',
            other: 'Other Documents',
        };

        return labels[groupKey] || 'Other Documents';
    }

    function renderIconButton(icon, label, attributes = '', extraClass = '') {
        return `<button type="button" class="crm-button crm-button--ghost crm-button--small crm-button--icon ${extraClass}" title="${escapeHtml(label)}" aria-label="${escapeHtml(label)}" ${attributes}>${renderActionIcon(icon)}</button>`;
    }

    function renderActionIcon(icon) {
        if (icon === 'whatsapp') {
            return `
                <svg class="crm-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M20.5 3.5A11 11 0 0 0 3.7 17.1L2 22l5.1-1.7A11 11 0 1 0 20.5 3.5zm-8.5 16a8.9 8.9 0 0 1-4.5-1.2l-.3-.2-3 .9 1-2.9-.2-.3A9 9 0 1 1 12 19.5zm5-6.7c-.3-.2-1.7-.9-2-.9s-.4-.2-.6.2-.7.9-.8 1.1-.3.2-.6.1a7.2 7.2 0 0 1-2.1-1.3 8 8 0 0 1-1.5-1.9c-.2-.3 0-.4.1-.6l.4-.5.3-.5a.5.5 0 0 0 0-.5c0-.1-.6-1.5-.9-2-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.4 3 3 0 0 0-1 2.2c0 1.3 1 2.6 1.1 2.8.1.2 2 3.1 4.9 4.3.7.3 1.2.5 1.7.6.7.2 1.3.2 1.8.1.5-.1 1.7-.7 2-1.4.2-.7.2-1.3.1-1.4 0-.1-.2-.2-.5-.4z" fill="currentColor"></path>
                </svg>
            `;
        }

        if (icon === 'view') {
            return `
                <svg class="crm-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5c5.5 0 9.5 5.4 10.7 7-1.2 1.6-5.2 7-10.7 7S2.5 13.6 1.3 12C2.5 10.4 6.5 5 12 5zm0 2.2A4.8 4.8 0 1 0 12 16.8 4.8 4.8 0 0 0 12 7.2zm0 2A2.8 2.8 0 1 1 12 14.8 2.8 2.8 0 0 1 12 9.2z" fill="currentColor"></path>
                </svg>
            `;
        }

        if (icon === 'delete') {
            return `
                <svg class="crm-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 7h2v8h-2v-8zm4 0h2v8h-2v-8zM7 10h2v8H7v-8z" fill="currentColor"></path>
                </svg>
            `;
        }

        return `
            <svg class="crm-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 5c5.5 0 9.5 5.4 10.7 7-1.2 1.6-5.2 7-10.7 7S2.5 13.6 1.3 12C2.5 10.4 6.5 5 12 5zm0 2.2A4.8 4.8 0 1 0 12 16.8 4.8 4.8 0 0 0 12 7.2zm0 2A2.8 2.8 0 1 1 12 14.8 2.8 2.8 0 0 1 12 9.2z" fill="currentColor"></path>
            </svg>
        `;
    }

    function sortUploadedDocumentsForDisplay(documents) {
        return [...(documents || [])].sort((left, right) => {
            const leftPriority = documentDisplayOrderPriority(left);
            const rightPriority = documentDisplayOrderPriority(right);

            if (leftPriority !== rightPriority) {
                return leftPriority - rightPriority;
            }

            const leftUploaded = new Date(left.uploaded_at || 0).getTime();
            const rightUploaded = new Date(right.uploaded_at || 0).getTime();

            if (leftUploaded !== rightUploaded) {
                return leftUploaded - rightUploaded;
            }

            return Number(left.id || 0) - Number(right.id || 0);
        });
    }

    function documentDisplayOrderPriority(document) {
        const assignmentKey = filledAssignmentKey(document);
        const orderedAssignments = ['ic_front', 'ic_back', 'payslip_1', 'payslip_2', 'payslip_3', 'epf_year_1', 'epf_year_2', 'ramci', 'ctos'];
        const typePriority = {
            ic: 20,
            payslip: 30,
            epf: 40,
            ramci: 50,
            ctos: 60,
            other: 90,
        };

        if (assignmentKey) {
            const assignmentIndex = orderedAssignments.indexOf(assignmentKey);

            if (assignmentIndex !== -1) {
                return assignmentIndex;
            }
        }

        const classification = document.classification || {};
        const detectedType = document.effective_document_type || classification.document_type || document.document_type || 'other';

        return typePriority[detectedType] ?? 90;
    }

    function filledAssignmentKey(document) {
        return document.manual_assignment_key || document.assigned_checklist_key || inferAssignmentFromDocument(document) || '';
    }


    function renderCalculationStage(lead, latestCalculation) {
        if (!lead.document_completeness?.is_complete) {
            return `<section class="crm-card crm-card--solid"><div class="crm-card-body"><div class="crm-empty"><strong>Document stage incomplete</strong><span>Upload IC front and back, three payslips, RAMCI, and CTOS before calculation becomes available.</span></div></div></section>`;
        }

        return `
            <section class="crm-card crm-card--solid">
                <div class="crm-card-head"><div><h3 class="crm-card-title">Calculation Stage</h3><p class="crm-card-note">Run the finance calculation after all required documents are complete.</p></div></div>
                <div class="crm-card-body crm-stack">
                    <form id="calculation-form" class="crm-form-grid">
                        <div class="crm-detail-grid">
                            <div class="crm-field"><label>Requested Amount</label><input class="crm-input" name="requested_amount" value="${escapeHtml(state.calculationDefaults.requested_amount)}"></div>
                            <div class="crm-field"><label>Tenure Months</label><input class="crm-input" name="tenure_months" type="number" value="${state.calculationDefaults.tenure_months}"></div>
                            <div class="crm-field"><label>Annual Interest Rate</label><input class="crm-input" name="annual_interest_rate" type="number" step="0.1" value="${state.calculationDefaults.annual_interest_rate}"></div>
                            <div class="crm-field"><label>Max DSR %</label><input class="crm-input" name="max_dsr_percentage" type="number" step="0.1" value="${state.calculationDefaults.max_dsr_percentage}"></div>
                        </div>
                        <div class="crm-inline">
                            <button class="crm-button" ${state.loading ? 'disabled' : ''}>Run Calculation</button>
                        </div>
                    </form>
                    ${latestCalculation ? renderCalculationSummary(latestCalculation) : '<div class="crm-empty"><strong>No calculation result yet.</strong><span>Run calculation to unlock the bank match stage.</span></div>'}
                </div>
            </section>
        `;
    }

    function renderBankMatchStage(lead) {
        const latestCalculation = lead.calculation_results?.[lead.calculation_results.length - 1] || null;

        if (!latestCalculation) {
            return `<section class="crm-card crm-card--solid"><div class="crm-card-body"><div class="crm-empty"><strong>Calculation stage incomplete</strong><span>Run calculation before bank matching becomes available.</span></div></div></section>`;
        }

        return `
            <section class="crm-stack">
                <section class="crm-card crm-card--solid">
                    <div class="crm-card-head"><div><h3 class="crm-card-title">Bank Match Stage</h3><p class="crm-card-note">Use the latest calculation result to generate bank matches.</p></div></div>
                    <div class="crm-card-body crm-stack">
                        <div class="crm-inline">
                            <button type="button" class="crm-button crm-button--warn" data-action="run-bank-match" ${state.loading ? 'disabled' : ''}>Run Bank Match</button>
                        </div>
                        <div class="crm-table">
                            ${lead.bank_matches?.length ? `<table><thead><tr><th>Bank</th><th>Status</th><th>Reason</th></tr></thead><tbody>${lead.bank_matches.map((match) => `<tr><td>${escapeHtml(match.bank?.name || 'Unknown')}</td><td><span class="crm-badge" data-tone="${stageTone(match.match_status)}">${escapeHtml(match.match_status.replaceAll('_', ' '))}</span></td><td>${escapeHtml(match.match_reason || 'No explanation')}</td></tr>`).join('')}</tbody></table>` : '<div class="crm-empty">No bank match results yet.</div>'}
                        </div>
                    </div>
                </section>

                <section class="crm-card crm-card--solid">
                    <div class="crm-card-head"><div><h3 class="crm-card-title">Stage History</h3><p class="crm-card-note">Timeline of stage changes and lead activity.</p></div></div>
                    <div class="crm-card-body crm-table">
                        ${lead.stage_history?.length ? `
                            <table>
                                <thead><tr><th>Old Stage</th><th>New Stage</th><th>Note</th><th>Changed</th></tr></thead>
                                <tbody>
                                    ${lead.stage_history.map((history) => `
                                        <tr>
                                            <td>${escapeHtml(history.old_stage ? String(history.old_stage).replaceAll('_', ' ') : 'N/A')}</td>
                                            <td>${escapeHtml(String(history.new_stage || '').replaceAll('_', ' '))}</td>
                                            <td>${escapeHtml(history.note || 'No note')}</td>
                                            <td>${formatDateTime(history.changed_at)}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        ` : '<div class="crm-empty">No stage changes recorded yet.</div>'}
                    </div>
                </section>
            </section>
        `;
    }

    function renderCalculationSummary(result) {
        return `<div class="crm-detail-grid"><div class="crm-kv"><span class="crm-kv-label">Recognized Income</span><span class="crm-kv-value">${formatMoney(result.total_recognized_income)}</span></div><div class="crm-kv"><span class="crm-kv-label">Commitments</span><span class="crm-kv-value">${formatMoney(result.total_commitments)}</span></div><div class="crm-kv"><span class="crm-kv-label">DSR</span><span class="crm-kv-value">${result.dsr_result ?? 'N/A'}%</span></div><div class="crm-kv"><span class="crm-kv-label">Allowed Financing</span><span class="crm-kv-value">${formatMoney(result.allowed_financing_amount)}</span></div><div class="crm-kv"><span class="crm-kv-label">Installment</span><span class="crm-kv-value">${formatMoney(result.installment)}</span></div><div class="crm-kv"><span class="crm-kv-label">Payout Estimate</span><span class="crm-kv-value">${formatMoney(result.payout_result)}</span></div></div>`;
    }

    function bindEvents() {
        document.querySelectorAll('[data-action="pick-image"]').forEach((button) => {
            button.addEventListener('click', () => document.querySelector('#lead-image-input')?.click());
        });

        document.querySelector('[data-action="clear-image"]')?.addEventListener('click', clearIntakeQueue);

        document.querySelector('#lead-image-input')?.addEventListener('change', (event) => {
            const files = imageFilesFromList(event.currentTarget.files || []);

            if (files.length) {
                queueIntakeFiles(files, 'upload');
            }

            event.currentTarget.value = '';
        });

        document.querySelector('#source')?.addEventListener('input', (event) => {
            setSourceLabel(event.currentTarget.value);
        });

        document.querySelectorAll('[data-remove-image]').forEach((button) => {
            button.addEventListener('click', () => removeQueuedImage(Number(button.dataset.removeImage)));
        });

        document.querySelectorAll('[data-row-index]').forEach((input) => {
            input.addEventListener('input', (event) => {
                updateExtractedRow(Number(input.dataset.rowIndex), input.dataset.rowField, event.currentTarget.value);
            });
        });

        document.querySelectorAll('[data-remove-row]').forEach((button) => {
            button.addEventListener('click', () => removeExtractedRow(Number(button.dataset.removeRow)));
        });

        document.querySelector('[data-action="import-extracted"]')?.addEventListener('click', importExtractedRows);

        document.querySelector('#filter-form')?.addEventListener('submit', (event) => {
            event.preventDefault();
        });

        document.querySelector('#search')?.addEventListener('input', (event) => {
            updateWorkspaceFilters({ search: event.currentTarget.value }, { debounce: true });
        });

        document.querySelector('#stage-filter')?.addEventListener('change', (event) => {
            updateWorkspaceFilters({ stage: event.currentTarget.value });
        });

        document.querySelector('#date-filter')?.addEventListener('change', (event) => {
            updateWorkspaceFilters({ date: event.currentTarget.value });
        });

        document.querySelector('#recent-filter')?.addEventListener('change', (event) => {
            updateWorkspaceFilters({ recent: event.currentTarget.checked });
        });

        document.querySelector('[data-action="clear-filters"]')?.addEventListener('click', async () => {
            if (filterDebounceId) {
                window.clearTimeout(filterDebounceId);
                filterDebounceId = null;
            }

            state.filters = { search: '', stage: '', date: '', recent: false };
            state.pagination.current_page = 1;
            render();
            await loadLeads();
        });

        document.querySelectorAll('[data-page-nav]').forEach((button) => {
            button.addEventListener('click', async () => {
                const direction = button.dataset.pageNav;
                const nextPage = direction === 'prev'
                    ? state.pagination.current_page - 1
                    : state.pagination.current_page + 1;

                await loadLeads(nextPage);
            });
        });

        document.querySelectorAll('[data-view-lead]').forEach((element) => {
            element.addEventListener('click', async () => {
                await loadLead(element.dataset.viewLead);
            });
        });

        document.querySelectorAll('[data-delete-lead]').forEach((element) => {
            element.addEventListener('click', async () => {
                await deleteLead(element.dataset.deleteLead);
            });
        });

        document.querySelectorAll('[data-action="close-modal"]').forEach((element) => {
            element.addEventListener('click', (event) => {
                event.preventDefault();
                closeLeadModal();
            });
        });

        document.querySelector('.crm-modal-shell')?.addEventListener('click', (event) => {
            event.stopPropagation();
        });

        document.querySelectorAll('[data-action="pick-documents"]').forEach((button) => {
            button.addEventListener('click', () => document.querySelector('#lead-document-input')?.click());
        });

        document.querySelector('#lead-document-input')?.addEventListener('change', async (event) => {
            await uploadLeadDocuments(event.currentTarget.files || []);
            event.currentTarget.value = '';
        });

        document.querySelector('[data-document-stage-dropzone]')?.addEventListener('dragenter', (event) => {
            if (!transferHasFiles(event.dataTransfer)) {
                return;
            }

            event.preventDefault();
            documentStageDragDepth += 1;

            if (!state.documentStageDragActive) {
                state.documentStageDragActive = true;
                render();
            }
        });

        document.querySelector('[data-document-stage-dropzone]')?.addEventListener('dragover', (event) => {
            event.preventDefault();

            if (!state.documentStageDragActive) {
                state.documentStageDragActive = true;
                render();
            }
        });

        document.querySelector('[data-document-stage-dropzone]')?.addEventListener('dragleave', (event) => {
            if (!transferHasFiles(event.dataTransfer)) {
                return;
            }

            event.preventDefault();
            documentStageDragDepth = Math.max(0, documentStageDragDepth - 1);

            if (documentStageDragDepth === 0 && state.documentStageDragActive) {
                state.documentStageDragActive = false;
                render();
            }
        });

        document.querySelector('[data-document-stage-dropzone]')?.addEventListener('drop', async (event) => {
            event.preventDefault();
            documentStageDragDepth = 0;
            state.documentStageDragActive = false;
            await uploadLeadDocuments(event.dataTransfer?.files || []);
        });

        document.querySelectorAll('[data-document-assignment]').forEach((select) => {
            select.addEventListener('change', async (event) => {
                await updateDocumentAssignment(select.dataset.documentAssignment, event.currentTarget.value);
            });
        });

        document.querySelector('[data-select-all-documents]')?.addEventListener('change', (event) => {
            toggleAllDocumentSelections(event.currentTarget.checked);
        });

        document.querySelector('[data-select-all-toggle]')?.addEventListener('click', () => {
            const selectableCount = uploadedDocumentsSelectableCount(state.selectedLead?.documents || []);
            const shouldSelectAll = !(selectableCount && state.selectedDocumentIds.length === selectableCount);
            toggleAllDocumentSelections(shouldSelectAll);
        });

        document.querySelectorAll('[data-document-select]').forEach((input) => {
            input.addEventListener('change', (event) => {
                toggleDocumentSelection(input.dataset.documentSelect, event.currentTarget.checked);
            });
        });

        document.querySelector('[data-bulk-delete-documents]')?.addEventListener('click', async () => {
            await bulkDeleteLeadDocuments();
        });

        document.querySelectorAll('[data-preview-document]').forEach((button) => {
            button.addEventListener('click', () => {
                previewLeadDocument(button.dataset.previewDocument);
            });
        });

        document.querySelectorAll('[data-delete-document]').forEach((button) => {
            button.addEventListener('click', async () => {
                await deleteLeadDocument(button.dataset.deleteDocument);
            });
        });

        document.querySelectorAll('[data-workflow-stage]').forEach((button) => {
            button.addEventListener('click', () => {
                setLeadWorkflowStage(button.dataset.workflowStage);
            });
        });

        document.querySelector('#calculation-form')?.addEventListener('submit', (event) => {
            event.preventDefault();
            runCalculation(event.currentTarget);
        });

        document.querySelector('[data-action="run-bank-match"]')?.addEventListener('click', runBankMatch);
    }

    function bindGlobalWorkspaceEvents() {
        if (workspaceGlobalsBound) {
            return;
        }

        workspaceGlobalsBound = true;

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && state.selectedLeadId) {
                closeLeadModal();
            }
        });
    }

    function updateWorkspaceFilters(nextFilters, { debounce = false } = {}) {
        state.filters = {
            ...state.filters,
            ...nextFilters,
        };
        state.pagination.current_page = 1;

        render();

        if (filterDebounceId) {
            window.clearTimeout(filterDebounceId);
            filterDebounceId = null;
        }

        if (debounce) {
            filterDebounceId = window.setTimeout(() => {
                loadLeads();
            }, 250);

            return;
        }

        loadLeads();
    }

    function renderStageOptions(selected, includeAll = false) {
        const stages = ['NEW_LEAD','CONTACT_READY','DOC_REQUESTED','DOC_PARTIAL','DOC_COMPLETE','PROCESSING','PROCESSED','MATCHED','NOT_ELIGIBLE','MANUAL_REVIEW','CLOSED'];
        const options = includeAll ? [''].concat(stages) : stages;
        return options.map((value) => `<option value="${value}" ${String(selected || '') === value ? 'selected' : ''}>${escapeHtml(value ? value.replaceAll('_', ' ') : 'All stages')}</option>`).join('');
    }

    function stageTone(value) {
        if (!value) return 'neutral';
        if (['MATCHED', 'eligible', 'matched', 'completed', 'high'].includes(value)) return 'matched';
        if (['MANUAL_REVIEW', 'manual_review', 'conditional', 'review_required', 'incomplete', 'medium'].includes(value)) return 'review';
        if (['NOT_ELIGIBLE', 'not_eligible', 'failed', 'not_matched', 'low'].includes(value)) return 'failed';
        return 'stage';
    }

    function availableWorkflowStages(lead) {
        const docsComplete = Boolean(lead.document_completeness?.is_complete);
        const hasCalculation = Boolean(lead.calculation_results?.length);

        return [
            {
                key: 'documents',
                label: 'Document Stage',
                description: 'Upload all required files',
                locked: false,
            },
            {
                key: 'calculation',
                label: 'Calculation Stage',
                description: docsComplete ? 'Ready to calculate' : 'Unlocks after documents complete',
                locked: !docsComplete,
            },
            {
                key: 'bank_match',
                label: 'Bank Match Stage',
                description: hasCalculation ? 'Ready for bank matching' : 'Unlocks after calculation',
                locked: !hasCalculation,
            },
        ];
    }

    function resolveWorkflowStage(lead, preferredStage) {
        const stages = availableWorkflowStages(lead);
        const preferred = stages.find((stage) => stage.key === preferredStage && !stage.locked);

        if (preferred) {
            return preferred.key;
        }

        return stages.find((stage) => !stage.locked)?.key || 'documents';
    }

    function documentTypeTitle(value) {
        const labels = {
            ic: 'Upload IC',
            payslip: 'Upload Payslip',
            epf: 'Upload EPF',
            ramci: 'Upload RAMCI',
            ctos: 'Upload CTOS',
            other: 'Unclassified',
        };

        return labels[value] || String(value || '').replaceAll('_', ' ');
    }

    function renderChecklistAssignmentOptions(selected) {
        const options = [
            ['', 'Unassigned'],
            ['ic_front', 'IC Front'],
            ['ic_back', 'IC Back'],
            ['payslip_1', 'Payslip Month 1'],
            ['payslip_2', 'Payslip Month 2'],
            ['payslip_3', 'Payslip Month 3'],
            ['epf_year_1', 'EPF Year 1'],
            ['epf_year_2', 'EPF Year 2'],
            ['ramci', 'RAMCI'],
            ['ctos', 'CTOS'],
        ];

        return options.map(([value, label]) => `<option value="${value}" ${selected === value ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('');
    }

    function inferAssignmentFromDocument(document) {
        const classification = document.classification || {};
        const type = document.effective_document_type || classification.document_type || document.document_type;

        if (type === 'ic') {
            return classification.ic_side === 'back' ? 'ic_back' : classification.ic_side === 'front' ? 'ic_front' : '';
        }

        if (type === 'ramci' || type === 'ctos') {
            return type;
        }

        return '';
    }

    function formatMoney(value) {
        if (value === null || value === undefined || value === '') return 'N/A';
        const amount = Number(value);
        if (Number.isNaN(amount)) return String(value);
        return new Intl.NumberFormat('en-MY', { style: 'currency', currency: 'MYR', maximumFractionDigits: 2 }).format(amount);
    }

    function formatDateTime(value) {
        if (!value) return 'N/A';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat('en-MY', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(date);
    }

    function whatsappLink(value) {
        const normalized = String(value || '').replace(/\s+/g, '').replace(/-/g, '');

        if (!normalized) {
            return '';
        }

        if (normalized.startsWith('+60')) {
            return `https://wa.me/${normalized}`;
        }

        if (normalized.startsWith('60')) {
            return `https://wa.me/+${normalized}`;
        }

        if (normalized.startsWith('0')) {
            return `https://wa.me/+60${normalized.slice(1)}`;
        }

        return `https://wa.me/+60${normalized}`;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
}
