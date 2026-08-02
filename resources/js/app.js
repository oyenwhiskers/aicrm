const appRoot = document.querySelector('#app');

if (appRoot) {
    const apiBase = appRoot.dataset.apiBase || '/api';
    const appName = appRoot.dataset.appName || 'Leads Processing System (LPS)';
    const appShortName = appRoot.dataset.appShortName || 'LPS';
    const appTitle = appName.replace(/\s*\(LPS\)\s*$/i, '').trim() || appName;
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
    const documentUploadChunkTargetBytes = 10 * 1024 * 1024;
    const documentUploadChunkMaxFiles = 3;
    const documentUploadMultipartOverheadBytes = 256 * 1024;

    const state = {
        appName,
        appShortName,
        appTitle,
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
        filters: { search: '', stage: initialStageFilter(), date: '', recent: false },
        leadSort: { field: '', direction: 'asc' },
        pagination: { total: 0, current_page: 1, last_page: 1 },
        loadingDashboard: false,
        dashboardLeads: [],
        dashboardTodayTotal: 0,
        dashboardTotal: 0,
        confirmDialog: null,
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

        if (state.page === 'dashboard') {
            await loadDashboardData();
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

    function initialStageFilter() {
        try {
            const stage = new URLSearchParams(window.location.search).get('stage');
            return stage ? stage.toUpperCase() : '';
        } catch (error) {
            return '';
        }
    }

    function activeDocumentStatuses() {
        return ['queued', 'processing', 'deleting'];
    }

    function deleteBlockedDocumentStatuses() {
        return ['processing', 'deleting'];
    }

    function documentIsActive(document) {
        return activeDocumentStatuses().includes(String(document?.upload_status || ''));
    }

    function documentBlocksDeletion(document) {
        return deleteBlockedDocumentStatuses().includes(String(document?.upload_status || ''));
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

        refreshLeadModalView();
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
            if (state.selectedLeadId) {
                return;
            }

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

    function refreshLeadModalView({ preserveScroll = true } = {}) {
        if (!state.selectedLeadId || !state.selectedLead) {
            render();
            return;
        }

        const modalBody = document.querySelector('.crm-modal-body');
        const overlayMount = document.querySelector('#crm-lead-modal-overlay');
        const headerMain = document.querySelector('#crm-lead-modal-header-main');
        const headerSide = document.querySelector('#crm-lead-modal-header-side');
        const workflowNav = document.querySelector('#crm-lead-workflow-nav');
        const stagePanel = document.querySelector('#crm-lead-stage-panel');

        if (!modalBody || !overlayMount || !headerMain || !headerSide || !workflowNav || !stagePanel) {
            render();
            return;
        }

        const scrollTop = preserveScroll ? modalBody.scrollTop : 0;
        const lead = state.selectedLead;
        const latestCalculation = lead.calculation_results?.[lead.calculation_results.length - 1] || null;
        const activeStage = resolveWorkflowStage(lead, state.activeLeadWorkflowStage);

        overlayMount.innerHTML = renderModalBusyOverlay();
        headerMain.innerHTML = renderLeadModalHeaderMain(lead);
        headerSide.innerHTML = renderLeadModalHeaderSide(lead);
        workflowNav.innerHTML = renderLeadWorkflowTabs(lead, activeStage);
        stagePanel.innerHTML = renderWorkflowStagePanel(lead, activeStage, latestCalculation);

        bindLeadModalEvents();

        if (!preserveScroll) {
            return;
        }

        window.requestAnimationFrame(() => {
            modalBody.scrollTop = scrollTop;
        });
    }

    function bindOnce(element, key, eventName, handler) {
        if (!element) {
            return;
        }

        const flag = `bound${key}`;
        if (element.dataset[flag] === '1') {
            return;
        }

        element.dataset[flag] = '1';
        element.addEventListener(eventName, handler);
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
        state.extractedRows = [];
        state.extractedSummary = null;
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
                source: row.source_image || state.sourceLabel || 'image extraction',
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

    async function loadDashboardData() {
        state.loadingDashboard = true;
        render();

        try {
            const today = new Date().toISOString().slice(0, 10);
            const [allPayload, todayPayload] = await Promise.all([
                apiRequest('/leads?per_page=100'),
                apiRequest(`/leads?per_page=100&date=${today}`),
            ]);

            state.dashboardLeads = allPayload.data || [];
            state.dashboardTodayTotal = todayPayload.total || 0;
            state.dashboardTotal = allPayload.total || 0;
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loadingDashboard = false;
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
            if (state.leadSort.field) {
                params.set('sort', state.leadSort.field);
                params.set('direction', state.leadSort.direction);
            }

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
        const shouldRefreshLeadList = pendingLeadListRefresh;

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

        if (shouldRefreshLeadList) {
            void loadLeads();
        }
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
        refreshLeadModalView({ preserveScroll: false });
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
        const uploadChunks = chunkDocumentUploads(uploadFiles);
        let uploadedCount = 0;
        let lastPayload = null;

        state.loading = true;
        state.uploadingDocuments = true;
        state.modalBusyMessage = buildDocumentUploadBusyMessage(0, uploadFiles.length, 0, uploadChunks.length);
        state.documentStageDragActive = false;
        refreshLeadModalView();

        try {
            for (let index = 0; index < uploadChunks.length; index += 1) {
                const chunk = uploadChunks[index];
                const data = new FormData();
                chunk.forEach((file) => data.append('files[]', file));

                state.modalBusyMessage = buildDocumentUploadBusyMessage(uploadedCount, uploadFiles.length, index + 1, uploadChunks.length);
                refreshLeadModalView();

                const payload = await apiRequest(`/leads/${state.selectedLeadId}/documents/batch`, {
                    method: 'POST',
                    body: data,
                });

                uploadedCount += Number(payload?.data?.uploaded_count || chunk.length);
                lastPayload = payload;
                applyLeadStatusPayload(payload.data);
                refreshLeadModalView();
            }

            pendingLeadListRefresh = true;
            pushNotice(`Queued ${uploadedCount} document${uploadedCount === 1 ? '' : 's'} for background processing.`);
            syncLeadStatusPolling();
        } catch (error) {
            pushNotice(error.message, 'error');
        } finally {
            state.loading = false;
            state.uploadingDocuments = false;
            state.modalBusyMessage = '';
            refreshLeadModalView();
        }
    }

    function chunkDocumentUploads(files) {
        const chunks = [];
        let currentChunk = [];
        let currentChunkBytes = 0;

        for (const file of files) {
            const fileBytes = Number(file?.size || 0);
            const projectedBytes = currentChunkBytes + fileBytes + documentUploadMultipartOverheadBytes;
            const exceedsSizeTarget = currentChunk.length > 0 && projectedBytes > documentUploadChunkTargetBytes;
            const exceedsFileTarget = currentChunk.length >= documentUploadChunkMaxFiles;

            if (exceedsSizeTarget || exceedsFileTarget) {
                chunks.push(currentChunk);
                currentChunk = [];
                currentChunkBytes = 0;
            }

            currentChunk.push(file);
            currentChunkBytes += fileBytes;
        }

        if (currentChunk.length) {
            chunks.push(currentChunk);
        }

        return chunks;
    }

    function buildDocumentUploadBusyMessage(uploadedCount, totalCount, chunkNumber, chunkTotal) {
        if (chunkTotal <= 1) {
            return 'Uploading documents and updating checklist...';
        }

        const uploadedLabel = uploadedCount > 0
            ? `Queued ${uploadedCount} of ${totalCount} documents. `
            : '';

        return `${uploadedLabel}Uploading batch ${chunkNumber} of ${chunkTotal} and updating checklist...`;
    }

    async function updateDocumentAssignment(documentId, assignmentKey) {
        if (!state.selectedLeadId) {
            return;
        }

        state.loading = true;
        state.modalBusyMessage = 'Updating document assignment...';
        refreshLeadModalView();

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
            refreshLeadModalView();
        }
    }

    function deleteLeadDocument(documentId) {
        if (!state.selectedLeadId) {
            return;
        }

        requestConfirm({
            title: 'Delete Document',
            message: 'Delete this uploaded document? This action cannot be undone.',
            confirmLabel: 'Delete Document',
            onConfirm: () => performDeleteLeadDocument(documentId),
        });
    }

    async function performDeleteLeadDocument(documentId) {
        if (!state.selectedLeadId) {
            return;
        }

        state.loading = true;
        state.modalBusyMessage = 'Deleting document...';
        refreshLeadModalView();

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
            refreshLeadModalView();
        }
    }

    function bulkDeleteLeadDocuments() {
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

        requestConfirm({
            title: 'Delete Documents',
            message: `Delete ${selectedIds.length} selected document${selectedIds.length === 1 ? '' : 's'}? This action cannot be undone.`,
            confirmLabel: `Delete ${selectedIds.length} Document${selectedIds.length === 1 ? '' : 's'}`,
            onConfirm: () => performBulkDeleteLeadDocuments(selectedIds),
        });
    }

    async function performBulkDeleteLeadDocuments(selectedIds) {
        if (!state.selectedLeadId || !selectedIds.length) {
            return;
        }

        state.loading = true;
        state.modalBusyMessage = `Deleting ${selectedIds.length} document${selectedIds.length === 1 ? '' : 's'}...`;
        refreshLeadModalView();

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
            refreshLeadModalView();
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
        refreshLeadModalView();
    }

    function toggleAllDocumentSelections(checked) {
        const selectableIds = (state.selectedLead?.documents || [])
            .filter((document) => !documentBlocksDeletion(document))
            .map((document) => Number(document.id));

        state.selectedDocumentIds = checked ? selectableIds : [];
        refreshLeadModalView();
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

    function deleteLead(leadId) {
        const normalizedLeadId = Number(leadId);

        if (!Number.isInteger(normalizedLeadId)) {
            return;
        }

        requestConfirm({
            title: 'Delete Lead',
            message: 'Delete this lead and all related documents? This action cannot be undone.',
            confirmLabel: 'Delete Lead',
            onConfirm: () => performDeleteLead(normalizedLeadId),
        });
    }

    async function performDeleteLead(normalizedLeadId) {
        if (!Number.isInteger(normalizedLeadId)) {
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

    function requestConfirm({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', tone = 'danger', onConfirm }) {
        state.confirmDialog = { title, message, confirmLabel, cancelLabel, tone, onConfirm };
        render();
    }

    function closeConfirmDialog() {
        if (!state.confirmDialog) {
            return;
        }

        state.confirmDialog = null;
        render();
    }

    async function acceptConfirmDialog() {
        const action = state.confirmDialog?.onConfirm;

        state.confirmDialog = null;
        render();

        if (typeof action === 'function') {
            await action();
        }
    }

    function renderConfirmDialog() {
        if (!state.confirmDialog) {
            return '';
        }

        const { title, message, confirmLabel, cancelLabel, tone } = state.confirmDialog;
        const confirmClass = tone === 'danger' ? 'crm-button crm-button--danger' : 'crm-button';

        return `
            <section class="crm-modal-backdrop crm-confirm-backdrop" data-action="confirm-cancel">
                <div class="crm-card crm-card--solid crm-confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="crm-confirm-title" aria-describedby="crm-confirm-message">
                    <div class="crm-confirm-body">
                        <h2 class="crm-confirm-title" id="crm-confirm-title">${escapeHtml(title)}</h2>
                        <p class="crm-confirm-message" id="crm-confirm-message">${escapeHtml(message)}</p>
                    </div>
                    <div class="crm-confirm-actions">
                        <button type="button" class="crm-button crm-button--ghost" data-action="confirm-cancel">${escapeHtml(cancelLabel)}</button>
                        <button type="button" class="${confirmClass}" data-action="confirm-accept">${escapeHtml(confirmLabel)}</button>
                    </div>
                </div>
            </section>
        `;
    }

    function render() {
        rememberModalScrollPosition();

        appRoot.innerHTML = `
            <div class="crm-app">
                ${renderSidebar()}
                <div class="crm-main">
                    ${renderPageTopbar()}
                    <div class="crm-content crm-content--${state.page}">
                        ${renderNotices()}
                        ${renderPageContent()}
                    </div>
                </div>
                ${renderConfirmDialog()}
            </div>
        `;

        bindEvents();
        syncGlobalScrollLock();
        restoreModalScrollPosition();
    }

    function syncGlobalScrollLock() {
        const shouldLock = Boolean(state.selectedLeadId || state.confirmDialog);

        document.documentElement.classList.toggle('crm-scroll-locked', shouldLock);
        document.body.classList.toggle('crm-scroll-locked', shouldLock);
    }

    function pageMeta() {
        const pages = {
            dashboard: {
                title: 'Dashboard',
                description: 'Overview of leads, pipeline activity, and recent intake.',
            },
            intake: {
                title: 'Lead Intake',
                description: 'Upload screenshots, review extracted rows, and import leads.',
            },
            workspace: {
                title: 'Workspace',
                description: 'Manage leads, documents, calculations, and bank matching.',
            },
        };

        return pages[state.page] || pages.workspace;
    }

    function sidebarNavIcon(page) {
        const icons = {
            dashboard: '<rect x="3" y="3" width="7" height="9" rx="1.5"></rect><rect x="14" y="3" width="7" height="5" rx="1.5"></rect><rect x="14" y="12" width="7" height="9" rx="1.5"></rect><rect x="3" y="16" width="7" height="5" rx="1.5"></rect>',
            intake: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line>',
            workspace: '<rect x="3" y="4" width="18" height="16" rx="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="9" x2="9" y2="20"></line>',
        };

        return `<svg class="crm-sidebar-link-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[page] || ''}</svg>`;
    }

    function renderSidebar() {
        const navItems = [
            { page: 'dashboard', href: '/dashboard', label: 'Dashboard' },
            { page: 'intake', href: '/lead-intake', label: 'Lead Intake' },
            { page: 'workspace', href: '/workspace', label: 'Workspace' },
        ];

        return `
            <aside class="crm-sidebar">
                <div class="crm-sidebar-brand">
                    <div class="crm-brand-mark">${escapeHtml(appShortName)}</div>
                    <div class="crm-sidebar-brand-copy">
                        <strong>${escapeHtml(appTitle)}</strong>
                    </div>
                </div>
                <nav class="crm-sidebar-nav" aria-label="Main navigation">
                    ${navItems.map((item) => `
                        <a class="crm-sidebar-link ${state.page === item.page ? 'is-active' : ''}" href="${item.href}">
                            ${sidebarNavIcon(item.page)}
                            <span class="crm-sidebar-link-label">${escapeHtml(item.label)}</span>
                        </a>
                    `).join('')}
                </nav>
                <div class="crm-sidebar-foot">
                    <span class="crm-sidebar-foot-label">Operator flow</span>
                    <span class="crm-sidebar-foot-note">Intake → Workspace → Process</span>
                </div>
            </aside>
        `;
    }

    function renderPageTopbar() {
        const meta = pageMeta();

        return `
            <header class="crm-page-topbar">
                <div class="crm-page-topbar-copy">
                    <h1 class="crm-page-title">${escapeHtml(meta.title)}</h1>
                    <p class="crm-page-description">${escapeHtml(meta.description)}</p>
                </div>
            </header>
        `;
    }

    function renderPageContent() {
        if (state.page === 'dashboard') {
            return renderDashboardPage();
        }

        if (state.page === 'intake') {
            return renderIntakePage();
        }

        return renderWorkspacePage();
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

    function renderDashboardPage() {
        if (state.loadingDashboard) {
            return '<section class="crm-dashboard-page"><div class="crm-empty crm-empty--compact"><strong>Loading dashboard...</strong><span>Fetching lead summary.</span></div></section>';
        }

        const leads = state.dashboardLeads || [];
        const sampleSize = leads.length;
        const isSampled = state.dashboardTotal > sampleSize;
        const stageCounts = aggregateStageCounts(leads);
        const docsPending = leads.filter((lead) => (lead.documents_count ?? 0) === 0).length;
        const processedCount = leads.filter((lead) => ['PROCESSED', 'MATCHED', 'NOT_ELIGIBLE', 'MANUAL_REVIEW'].includes(lead.stage)).length;
        const recentCount = leads.filter((lead) => isRecentLead(lead.created_at)).length;
        const sampleNote = isSampled ? `Of latest ${sampleSize} loaded` : null;

        return `
            <section class="crm-dashboard-page">
                <div class="crm-metric-grid">
                    ${renderMetricCard("Today's Leads", state.dashboardTodayTotal, 'Imported today')}
                    ${renderMetricCard('Total Leads', state.dashboardTotal, 'All records')}
                    ${renderMetricCard('Docs Pending', docsPending, sampleNote || 'Awaiting documents')}
                    ${renderMetricCard('Processed', processedCount, sampleNote || 'Calculated or matched')}
                </div>

                <div class="crm-dashboard-grid">
                    <section class="crm-card crm-card--solid crm-dashboard-card">
                        <div class="crm-card-head">
                            <div>
                                <h2 class="crm-card-title">Pipeline By Stage</h2>
                                <p class="crm-card-note">${isSampled ? `Across the latest ${sampleSize} leads.` : 'Distribution across all leads.'}</p>
                            </div>
                        </div>
                        <div class="crm-card-body">
                            ${stageCounts.length ? `
                                <div class="crm-pipeline-list">
                                    ${stageCounts.map((item) => `
                                        <a class="crm-pipeline-row" href="/workspace?stage=${encodeURIComponent(item.stage)}">
                                            <div class="crm-pipeline-row-head">
                                                <span class="crm-badge crm-badge--compact" data-tone="${stageTone(item.stage)}">${escapeHtml(item.label)}</span>
                                                <strong>${item.count}</strong>
                                            </div>
                                            <div class="crm-pipeline-bar" aria-hidden="true">
                                                <span style="width: ${item.percent}%"></span>
                                            </div>
                                        </a>
                                    `).join('')}
                                </div>
                            ` : '<div class="crm-empty crm-empty--compact"><strong>No pipeline data yet</strong><span>Import leads from intake to populate this view.</span></div>'}
                        </div>
                    </section>

                    <section class="crm-card crm-card--solid crm-dashboard-card">
                        <div class="crm-card-head">
                            <div>
                                <h2 class="crm-card-title">Recent Leads</h2>
                                <p class="crm-card-note">${recentCount && recentCount < sampleSize ? `${recentCount} added in the last 24 hours.` : 'Latest imported leads.'}</p>
                            </div>
                            <a class="crm-button crm-button--ghost crm-button--small" href="/workspace">View All</a>
                        </div>
                        <div class="crm-card-body crm-stack">
                            ${leads.length ? renderDashboardRecentLeads(leads.slice(0, 8)) : '<div class="crm-empty crm-empty--compact"><strong>No leads yet</strong><span>Start with Lead Intake to create your first records.</span></div>'}
                        </div>
                    </section>
                </div>
            </section>
        `;
    }

    function renderMetricCard(label, value, note) {
        return `
            <article class="crm-metric-card">
                <span class="crm-metric-label">${escapeHtml(label)}</span>
                <strong class="crm-metric-value">${escapeHtml(String(value ?? 0))}</strong>
                <span class="crm-metric-note">${escapeHtml(note)}</span>
            </article>
        `;
    }

    function renderDashboardRecentLeads(leads) {
        return `
            <div class="crm-table crm-table--compact crm-dashboard-table">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Stage</th>
                            <th>Docs</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${leads.map((lead) => `
                            <tr class="crm-table-row-clickable" data-view-lead="${lead.id}">
                                <td>
                                    <div class="crm-table-primary">${escapeHtml(lead.name)}</div>
                                    <div class="crm-meta-text">${escapeHtml(lead.phone_number || 'No phone')}</div>
                                </td>
                                <td><span class="crm-badge crm-badge--compact" data-tone="${stageTone(lead.stage)}">${escapeHtml(formatStageLabel(lead.stage))}</span></td>
                                <td>${lead.documents_count ?? 0}</td>
                                <td><span class="crm-meta-text">${escapeHtml(formatRelativeTime(lead.created_at))}</span></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    function formatStageLabel(stage) {
        return String(stage || 'Unknown')
            .replaceAll('_', ' ')
            .toLowerCase()
            .replace(/\b\w/g, (char) => char.toUpperCase());
    }

    function formatRelativeTime(value) {
        if (!value) return 'N/A';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);

        const diffMs = Date.now() - date.getTime();
        const diffMin = Math.round(diffMs / 60000);

        if (diffMin < 1) return 'Just now';
        if (diffMin < 60) return `${diffMin}m ago`;

        const diffHours = Math.round(diffMin / 60);
        if (diffHours < 24) return `${diffHours}h ago`;

        const diffDays = Math.round(diffHours / 24);
        if (diffDays < 7) return `${diffDays}d ago`;

        return new Intl.DateTimeFormat('en-MY', {
            day: '2-digit',
            month: 'short',
        }).format(date);
    }

    function aggregateStageCounts(leads) {
        const counts = {};

        leads.forEach((lead) => {
            const key = lead.stage || 'UNKNOWN';
            counts[key] = (counts[key] || 0) + 1;
        });

        const total = leads.length || 1;

        return Object.entries(counts)
            .map(([stage, count]) => ({
                stage,
                label: formatStageLabel(stage),
                count,
                percent: Math.max(8, Math.round((count / total) * 100)),
            }))
            .sort((left, right) => right.count - left.count);
    }

    function isRecentLead(value) {
        if (!value) {
            return false;
        }

        const createdAt = new Date(value);
        const dayAgo = Date.now() - (24 * 60 * 60 * 1000);

        return createdAt.getTime() >= dayAgo;
    }

    function intakeExtractionInProgress() {
        if (state.loading) {
            return true;
        }

        return (state.intakeImages || []).some((image) => {
            const status = image.extractionStatus || 'queued';

            return status === 'queued' || status === 'processing' || status === 'retrying';
        });
    }

    function intakeUploadStatusLabel() {
        const images = state.intakeImages || [];

        if (!images.length) {
            return null;
        }

        const statusCounts = images.reduce((counts, image) => {
            const status = image.extractionStatus || 'queued';
            counts[status] = (counts[status] || 0) + 1;

            return counts;
        }, {});

        if (statusCounts.failed) {
            return {
                label: `${statusCounts.failed} failed`,
                tone: 'failed',
            };
        }

        if (statusCounts.retrying) {
            return {
                label: `${statusCounts.retrying} retrying`,
                tone: 'stage',
            };
        }

        if (statusCounts.processing) {
            return {
                label: `${statusCounts.processing} processing`,
                tone: 'stage',
            };
        }

        if (statusCounts.queued) {
            return {
                label: `${statusCounts.queued} queued`,
                tone: 'neutral',
            };
        }

        if (statusCounts.completed === images.length) {
            return null;
        }

        const doneCount = statusCounts.completed || 0;

        return {
            label: `${doneCount} done`,
            tone: 'matched',
        };
    }

    function intakeReviewConfidenceMeta() {
        const rows = state.extractedRows || [];

        if (!rows.length) {
            return { hideConfidence: false, sharedConfidence: null, summaryLabel: null };
        }

        const confidences = rows.map((row) => String(row.confidence || 'medium').toLowerCase());
        const uniqueConfidences = [...new Set(confidences)];

        if (uniqueConfidences.length !== 1) {
            return { hideConfidence: false, sharedConfidence: null, summaryLabel: null };
        }

        const sharedConfidence = uniqueConfidences[0];

        return {
            hideConfidence: true,
            sharedConfidence,
            summaryLabel: `All ${sharedConfidence} confidence`,
        };
    }

    function shouldShowIntakeReviewSummary() {
        if (!state.extractedSummary) {
            return false;
        }

        if (state.extractedRows.length > 0 && state.intakeBatchStatus === 'completed') {
            return false;
        }

        return true;
    }

    function renderIntakeReviewMeta() {
        const { hideSource, sharedSource } = intakeReviewSourceMeta();
        const { hideConfidence, summaryLabel } = intakeReviewConfidenceMeta();
        const parts = [];

        if (hideSource && sharedSource) {
            parts.push(escapeHtml(sharedSource));
        }

        if (hideConfidence && summaryLabel) {
            parts.push(escapeHtml(summaryLabel));
        }

        if (!parts.length) {
            return '';
        }

        return `<p class="crm-intake-review-meta">${parts.join(' · ')}</p>`;
    }

    function intakeReviewSourceMeta() {
        const rows = state.extractedRows || [];
        const sources = rows
            .map((row) => row.source_image || '')
            .filter(Boolean);

        if (!sources.length) {
            return { hideSource: false, sharedSource: null };
        }

        const uniqueSources = [...new Set(sources)];

        if (uniqueSources.length === 1) {
            return { hideSource: true, sharedSource: uniqueSources[0] };
        }

        return { hideSource: false, sharedSource: null };
    }

    function renderIntakePage() {
        const importLabel = state.extractedRows.length === 1
            ? 'Import 1 Lead'
            : `Import ${state.extractedRows.length} Leads`;
        const showUploadHelper = state.extractedRows.length === 0 || intakeExtractionInProgress();

        return `
            <section class="crm-intake-page crm-intake-layout">
                ${state.intakeDragActive ? '<div class="crm-intake-overlay"><strong>Drop image files anywhere</strong><span>LPS will add them to the intake queue automatically.</span></div>' : ''}

                <aside class="crm-intake-capture-rail ${state.intakeImages.length ? '' : 'crm-intake-capture-rail--empty'}">
                    <section class="crm-card crm-card--solid crm-intake-section crm-intake-upload-card">
                        <div class="crm-card-head">
                            <div>
                                <h2 class="crm-card-title">Upload</h2>
                                <p class="crm-card-note">${state.intakeImages.length ? 'Paste, drag, or drop to add more.' : 'Paste, drag, or drop screenshots.'}</p>
                            </div>
                            <div class="crm-card-head-actions">
                                ${state.intakeImages.length ? `<button type="button" class="crm-button crm-button--ghost crm-button--small" data-action="clear-image" ${state.loading ? 'disabled' : ''}>Reset</button>` : ''}
                            </div>
                        </div>
                        <div class="crm-card-body">
                            <div class="crm-intake-capture">
                                <input id="lead-image-input" type="file" name="image" accept="image/*" multiple hidden>
                                ${state.intakeImages.length ? '' : `
                                    <button type="button" class="crm-intake-surface crm-intake-surface--empty" data-action="pick-image" ${state.loading ? 'disabled' : ''}>
                                        <span class="crm-dropzone-icon" aria-hidden="true">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                                <polyline points="17 8 12 3 7 8"></polyline>
                                                <line x1="12" y1="3" x2="12" y2="15"></line>
                                            </svg>
                                        </span>
                                        <span class="crm-intake-surface-copy">
                                            <strong>Drop or select screenshots</strong>
                                            <span>Chat exports and lead lists supported.</span>
                                            <span class="crm-intake-surface-tip">Include name and phone in each row.</span>
                                        </span>
                                    </button>
                                `}

                                ${state.intakeImages.length ? `
                                    <div class="crm-intake-queue">
                                        ${state.intakeImages.map((image) => `
                                            <article class="crm-queue-item">
                                                <div class="crm-queue-item-main">
                                                    <strong title="${escapeHtml(image.name)}">${escapeHtml(image.name)}</strong>
                                                    ${renderIntakeImageProgress(image, { extrasOnly: true })}
                                                </div>
                                                <div class="crm-queue-item-aside">
                                                    ${renderIntakeImageProgress(image, { badgeOnly: true })}
                                                    <button type="button" class="crm-button crm-button--ghost crm-button--small crm-queue-remove" data-remove-image="${image.id}" ${state.loading ? 'disabled' : ''} aria-label="Remove file ${escapeHtml(image.name)}">Remove file</button>
                                                </div>
                                            </article>
                                        `).join('')}
                                    </div>
                                    <div class="crm-intake-add-row">
                                        <button type="button" class="crm-button crm-button--ghost crm-button--small" data-action="pick-image" ${state.loading ? 'disabled' : ''}>
                                            <span class="crm-intake-add-icon" aria-hidden="true">+</span>
                                            Add more
                                        </button>
                                    </div>
                                ` : ''}

                                ${showUploadHelper && state.intakeImages.length ? '<div class="crm-intake-footer-row"><p class="crm-footer-note">Include name and phone in each row.</p></div>' : ''}
                                ${renderIntakePerformanceInsights()}
                            </div>
                        </div>
                    </section>
                </aside>

                <div class="crm-intake-review-panel">
                    <section class="crm-card crm-card--solid crm-intake-section crm-intake-review-card">
                        <div class="crm-card-head">
                            <div>
                                <h2 class="crm-card-title">Extracted Leads</h2>
                                <p class="crm-card-note">Review and correct before import.</p>
                            </div>
                            <div class="crm-card-head-actions">
                                <span class="crm-badge" data-tone="neutral">${state.extractedRows.length} extracted</span>
                            </div>
                        </div>
                        <div class="crm-intake-review-body">
                            ${shouldShowIntakeReviewSummary() ? `<div class="crm-inline-summary">${escapeHtml(state.extractedSummary)}</div>` : ''}
                            ${state.extractedRows.length ? renderIntakeReviewRows() : `
                                <div class="crm-empty crm-empty--compact crm-intake-review-empty">
                                    <span class="crm-empty-icon" aria-hidden="true">
                                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="3" width="18" height="18" rx="3"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <path d="M21 15l-4.5-4.5L5 21"></path>
                                        </svg>
                                    </span>
                                    <strong>No extracted rows yet</strong>
                                    <span>Upload a screenshot to start extraction.</span>
                                </div>
                            `}
                        </div>
                        ${state.extractedRows.length ? `
                            <div class="crm-intake-review-actions">
                                <button type="button" class="crm-button" data-action="import-extracted" ${state.loading ? 'disabled' : ''}>${importLabel}</button>
                                <a class="crm-button crm-button--ghost" href="/workspace">Go To Workspace</a>
                            </div>
                        ` : ''}
                    </section>
                </div>
            </section>
        `;
    }

    function renderIntakeReviewRows() {
        const { hideSource } = intakeReviewSourceMeta();
        const { hideConfidence } = intakeReviewConfidenceMeta();
        const tableModifiers = [
            hideSource ? 'crm-intake-review-table--no-source' : '',
            hideConfidence ? 'crm-intake-review-table--no-confidence' : '',
        ].filter(Boolean);
        const tableClass = ['crm-intake-review-table', ...tableModifiers].join(' ');

        return `
            ${renderIntakeReviewMeta()}
            <div class="${tableClass}" role="table" aria-label="Extracted leads">
                <div class="crm-intake-review-head" role="row">
                    <span role="columnheader">#</span>
                    <span role="columnheader">Name</span>
                    <span role="columnheader">Phone</span>
                    ${hideConfidence ? '' : '<span role="columnheader">Confidence</span>'}
                    ${hideSource ? '' : '<span role="columnheader">Source</span>'}
                    <span role="columnheader" class="crm-intake-review-head-action">Actions</span>
                </div>
                <div class="crm-intake-review-rows">
                    ${state.extractedRows.map((row, index) => `
                        <article class="crm-intake-review-row" role="row">
                            <span class="crm-intake-review-num" role="cell">${index + 1}</span>
                            <input
                                class="crm-input crm-intake-review-input"
                                type="text"
                                data-row-index="${index}"
                                data-row-field="name"
                                value="${escapeHtml(row.name || '')}"
                                placeholder="Name"
                                aria-label="Name for lead ${index + 1}"
                            >
                            <input
                                class="crm-input crm-intake-review-input"
                                type="text"
                                data-row-index="${index}"
                                data-row-field="phone_number"
                                value="${escapeHtml(row.phone_number || '')}"
                                placeholder="Phone"
                                aria-label="Phone for lead ${index + 1}"
                            >
                            ${hideConfidence ? '' : `
                                <span class="crm-intake-review-confidence" role="cell" aria-label="Confidence ${escapeHtml(row.confidence || 'medium')}">
                                    <span class="crm-badge crm-badge--compact" data-tone="${stageTone(row.confidence)}">${escapeHtml((row.confidence || 'medium').toUpperCase())}</span>
                                </span>
                            `}
                            ${hideSource ? '' : `
                                <span class="crm-intake-review-source" role="cell" title="${escapeHtml(row.source_image || row.notes || '')}">
                                    ${row.source_image ? escapeHtml(row.source_image) : row.notes ? escapeHtml(row.notes) : '—'}
                                </span>
                            `}
                            <button type="button" class="crm-button crm-button--ghost crm-button--small crm-intake-review-remove" data-remove-row="${index}" aria-label="Remove lead ${index + 1}">Remove</button>
                        </article>
                    `).join('')}
                </div>
            </div>
        `;
    }

    function hasActiveFilters() {
        return Boolean(state.filters.search || state.filters.stage || state.filters.date || state.filters.recent);
    }

    function renderWorkspacePage() {
        return `
            <section class="crm-workspace-page crm-stack">
                <section class="crm-card crm-card--solid">
                    <div class="crm-card-body">
                        <form id="filter-form" class="crm-toolbar">
                            <div class="crm-toolbar-search">
                                <input class="crm-input" id="search" name="search" value="${escapeHtml(state.filters.search)}" placeholder="Search name, phone, or IC" aria-label="Search leads">
                            </div>
                            <select class="crm-select crm-toolbar-control" id="stage-filter" name="stage" aria-label="Filter by stage">
                                ${renderStageOptions(state.filters.stage, true)}
                            </select>
                            <input class="crm-input crm-toolbar-control" id="date-filter" name="date" type="date" value="${escapeHtml(state.filters.date)}" aria-label="Filter by date">
                            <label class="crm-toggle crm-toolbar-toggle">
                                <input type="checkbox" id="recent-filter" name="recent" ${state.filters.recent ? 'checked' : ''}>
                                <span>Recent</span>
                            </label>
                            ${hasActiveFilters() ? `<button type="button" class="crm-button crm-button--ghost crm-button--small crm-toolbar-clear" data-action="clear-filters" aria-label="Clear filters" title="Clear filters">&times;</button>` : ''}
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

    function renderIntakeImageProgress(image, options = {}) {
        const { badgeOnly = false, extrasOnly = false } = options;
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
            ? 'Done'
            : status === 'failed'
                ? 'Failed'
                : status === 'retrying'
                    ? 'Retrying'
                : status === 'processing'
                    ? 'Processing'
                    : 'Queued';

        const badge = `<span class="crm-badge crm-badge--compact" data-tone="${tone}">${escapeHtml(label)}</span>`;

        if (badgeOnly) {
            return badge;
        }

        const timingDetails = intakeImageTimingDetails(image);
        const pipelineSummary = renderIntakeImagePipeline(image);
        const preprocessSummary = renderIntakeImagePreprocess(image);
        const hasTechnicalDetails = Boolean(pipelineSummary || timingDetails || preprocessSummary);
        const showDetails = status === 'failed' || status === 'retrying';

        const extras = (status === 'failed' || status === 'retrying') ? `
            <div class="crm-queue-progress crm-queue-progress--compact">
                ${image.extractionError ? `<span class="crm-queue-error">${escapeHtml(image.extractionError)}</span>` : ''}
                ${hasTechnicalDetails && showDetails ? `
                    <details class="crm-queue-details">
                        <summary>Details</summary>
                        <div class="crm-queue-details-body">
                            ${pipelineSummary}
                            ${timingDetails ? `<span class="crm-meta-text">${escapeHtml(timingDetails)}</span>` : ''}
                            ${preprocessSummary}
                        </div>
                    </details>
                ` : ''}
            </div>
        ` : '';

        if (extrasOnly) {
            return extras;
        }

        return `
            <div class="crm-queue-progress crm-queue-progress--compact">
                ${badge}
                ${extras}
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
            <details class="crm-intake-performance-details">
                <summary class="crm-intake-performance-summary">
                    <span>Infrastructure insights</span>
                </summary>
                <div class="crm-intake-performance-card">
                    ${dominantStage ? `<p class="crm-meta-text">Bottleneck: ${escapeHtml(dominantStage)}</p>` : ''}
                    ${serialBatchProcessing ? `<span class="crm-meta-text">Serial batch processing detected: later images waited behind earlier ones.</span>` : ''}
                    <div class="crm-intake-performance-grid">
                        ${items.map((item) => `
                            <span class="crm-badge crm-badge--compact" data-tone="${item.tone}">${escapeHtml(`${item.label} ${item.value}`)}</span>
                        `).join('')}
                    </div>
                    ${performance?.recommendation ? `<p class="crm-card-note">${escapeHtml(performance.recommendation)}</p>` : ''}
                </div>
            </details>
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

    function renderLeadSortIndicator(field) {
        if (state.leadSort.field !== field) {
            return '<span class="crm-table-sort-indicator" aria-hidden="true">↕</span>';
        }

        return state.leadSort.direction === 'asc'
            ? '<span class="crm-table-sort-indicator" aria-hidden="true">↑</span>'
            : '<span class="crm-table-sort-indicator" aria-hidden="true">↓</span>';
    }

    function toggleLeadSort(field) {
        if (state.leadSort.field !== field) {
            state.leadSort = { field, direction: 'asc' };
        } else {
            state.leadSort = {
                field,
                direction: state.leadSort.direction === 'asc' ? 'desc' : 'asc',
            };
        }

        state.pagination.current_page = 1;
        void loadLeads();
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
                            <th>
                                <button type="button" class="crm-table-sort ${state.leadSort.field === 'name' ? 'is-active' : ''}" data-sort-field="name" aria-label="Sort leads by name">
                                    <span>Lead</span>
                                    ${renderLeadSortIndicator('name')}
                                </button>
                            </th>
                            <th>Phone</th>
                            <th>Stage</th>
                            <th>Docs</th>
                            <th>Source</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${state.leads.map((lead) => `
                            <tr class="crm-table-row-clickable" data-view-lead="${lead.id}">
                                <td>
                                    <div class="crm-table-primary">${escapeHtml(lead.name)}</div>
                                    <div class="crm-meta-text">${lead.ic_number ? escapeHtml(lead.ic_number) : 'IC pending'}</div>
                                </td>
                                <td>${escapeHtml(lead.phone_number || 'N/A')}</td>
                                <td><span class="crm-badge crm-badge--compact" data-tone="${stageTone(lead.stage)}">${escapeHtml(String(lead.stage || '').replaceAll('_', ' '))}</span></td>
                                <td>${lead.documents_count ?? 0}</td>
                                <td class="crm-table-source">${escapeHtml(formatLeadSourceDisplay(lead.source))}</td>
                                <td>${formatDateTime(lead.updated_at)}</td>
                                <td>
                                    <div class="crm-inline crm-inline--center crm-table-actions" data-stop-row-click>
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
                <div id="crm-lead-modal-overlay">${renderModalBusyOverlay()}</div>
                <div class="crm-card-head crm-modal-header">
                    <div class="crm-modal-header-main" id="crm-lead-modal-header-main">
                        ${renderLeadModalHeaderMain(lead)}
                    </div>
                    <div class="crm-modal-header-panel">
                        <div class="crm-modal-header-topbar">
                            <button type="button" class="crm-button crm-button--ghost crm-button--small crm-modal-close" data-action="close-modal" aria-label="Close" title="Close">&times;</button>
                        </div>
                        <div class="crm-modal-header-side" id="crm-lead-modal-header-side">
                            ${renderLeadModalHeaderSide(lead)}
                        </div>
                    </div>
                </div>
                <div class="crm-card-body crm-stack crm-modal-body">
                    <section class="crm-workflow-nav" id="crm-lead-workflow-nav">
                        ${renderLeadWorkflowTabs(lead, activeStage)}
                    </section>

                    <div id="crm-lead-stage-panel">
                        ${renderWorkflowStagePanel(lead, activeStage, latestCalculation)}
                    </div>
                </div>
            </section>
        `;
    }

    function renderLeadModalHeaderMain(lead) {
        return `
            <p class="crm-eyebrow">Lead Details</p>
            <h2 class="crm-modal-title">${escapeHtml(lead.name)}</h2>
        `;
    }

    function renderLeadModalHeaderSide(lead) {
        return `
            <div class="crm-modal-meta-row">
                <span class="crm-modal-meta-pill">${escapeHtml(lead.phone_number || 'Phone unavailable')}</span>
                ${lead.ic_number ? `<span class="crm-modal-meta-pill crm-modal-meta-pill--muted">IC ${escapeHtml(lead.ic_number)}</span>` : ''}
            </div>
            <span class="crm-badge crm-modal-stage-badge" data-tone="${stageTone(lead.stage)}">${escapeHtml(lead.stage.replaceAll('_', ' '))}</span>
        `;
    }

    function renderLeadWorkflowTabs(lead, activeStage) {
        return availableWorkflowStages(lead).map((stage, index) => `
            <button type="button" class="crm-workflow-tab ${activeStage === stage.key ? 'is-active' : ''} ${stage.locked ? 'is-locked' : ''}" data-workflow-stage="${stage.key}" ${stage.locked ? 'disabled' : ''}>
                <span class="crm-workflow-step">${index + 1}</span>
                <span>
                    <strong>${escapeHtml(stage.label)}</strong>
                    <small>${escapeHtml(stage.description)}</small>
                </span>
            </button>
        `).join('');
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
                    <div class="crm-card-head"><div><h3 class="crm-card-title">Upload</h3><p class="crm-card-note">Auto-detects IC, payslip, pension slip, EPF, RAMCI, and CTOS, then updates the checklist.</p></div><span class="crm-badge" data-tone="${lead.document_completeness?.is_complete ? 'matched' : lead.document_completeness?.has_review_items ? 'review' : 'stage'}">${lead.document_completeness?.received_required_slot_count || 0}/${lead.document_completeness?.required_document_slot_count || 0} matched</span></div>
                    <div class="crm-card-body crm-stack">
                        <div class="crm-bulk-upload" data-document-dropzone>
                            <input id="lead-document-input" type="file" accept="image/*,.pdf" multiple hidden>
                            <button type="button" class="crm-doc-dropzone ${state.uploadingDocuments ? 'is-busy' : ''}" data-action="pick-documents" ${state.uploadingDocuments ? 'disabled' : ''}>
                                <span class="crm-doc-dropzone-icon" aria-hidden="true">
                                    ${state.uploadingDocuments
                                        ? '<span class="crm-spinner"></span>'
                                        : '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4"></path><path d="m7 9 5-5 5 5"></path><path d="M5 16v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-2"></path></svg>'}
                                </span>
                                <span class="crm-doc-dropzone-copy">
                                    <strong>${state.uploadingDocuments ? 'Uploading and processing documents...' : 'Drag and drop files or click to upload'}</strong>
                                    <span>${state.uploadingDocuments ? 'Please wait while the checklist is updated.' : 'AI processing starts automatically after upload.'}</span>
                                </span>
                                ${state.uploadingDocuments ? '' : `
                                    <span class="crm-doc-dropzone-types">
                                        <span class="crm-doc-type-chip">JPG</span>
                                        <span class="crm-doc-type-chip">JPEG</span>
                                        <span class="crm-doc-type-chip">PNG</span>
                                        <span class="crm-doc-type-chip">WEBP</span>
                                        <span class="crm-doc-type-chip">PDF</span>
                                    </span>
                                `}
                            </button>
                        </div>
                    </div>
                </section>

                <section class="crm-card crm-card--solid">
                    ${renderChecklistCardHead(completenessItems)}
                    <div class="crm-card-body crm-table">
                        <table class="crm-checklist-table">
                            <thead>
                                <tr>
                                    <th>Requirement</th>
                                    <th>Status</th>
                                    <th>File / Detail</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${completenessItems.map((item) => `
                                    <tr class="crm-checklist-group-row">
                                        <td colspan="4">
                                            <div class="crm-checklist-group-head">
                                                <div class="crm-checklist-group-copy">
                                                    <strong>${escapeHtml(item.label)}</strong>
                                                </div>
                                                <span class="crm-checklist-group-progress">${renderChecklistGroupProgress(item)}</span>
                                            </div>
                                        </td>
                                    </tr>
                                    ${(item.slots || []).map((slot) => renderChecklistTableRow(slot)).join('')}
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section id="crm-uploaded-files" class="crm-card crm-card--solid crm-document-stage-last">
                    <div class="crm-card-head"><div><h3 class="crm-card-title">Uploaded files</h3><p class="crm-card-note">Review AI detection and correct checklist assignment when needed.</p></div></div>
                    <div class="crm-card-body crm-uploaded-files-body">
                        ${orderedDocuments.length ? `
                            ${renderUploadedDocumentBulkToolbar(orderedDocuments)}
                            <div class="crm-uploaded-files-table-wrap">
                                <table class="crm-uploaded-documents-table">
                                    <thead><tr><th><input type="checkbox" data-select-all-documents ${uploadedDocumentsSelectableCount(orderedDocuments) && state.selectedDocumentIds.length === uploadedDocumentsSelectableCount(orderedDocuments) ? 'checked' : ''} ${uploadedDocumentsSelectableCount(orderedDocuments) ? '' : 'disabled'}></th><th>File</th><th>AI Status</th><th>Checklist Assignment</th><th>Detail</th><th>Uploaded</th><th>Action</th></tr></thead>
                                    <tbody>
                                        ${renderUploadedDocumentRows(orderedDocuments, extractedByDocumentId)}
                                    </tbody>
                                </table>
                            </div>
                        ` : '<div class="crm-empty"><strong>No documents uploaded yet.</strong><span>Uploaded files will appear here after processing.</span></div>'}
                    </div>
                </section>
            </section>
        `;
    }

    function computeChecklistSummary(completenessItems) {
        const slots = (completenessItems || []).flatMap((item) => item.slots || []);

        return {
            total: slots.length,
            complete: slots.filter((slot) => slot.is_complete).length,
            missing: slots.filter((slot) => slot.is_missing).length,
            review: slots.filter((slot) => slot.needs_review).length,
        };
    }

    function renderChecklistCardHead(completenessItems) {
        const summary = computeChecklistSummary(completenessItems);

        return `
            <div class="crm-card-head crm-card-head--checklist">
                <div class="crm-checklist-head-copy">
                    <h3 class="crm-card-title">Checklist</h3>
                    <p class="crm-card-note">Track required documents and completion status for this lead.</p>
                </div>
                <div class="crm-checklist-summary">
                    <span class="crm-checklist-metric" data-tone="matched"><strong>${summary.complete}</strong><span>/ ${summary.total} documents complete</span></span>
                    <span class="crm-checklist-metric" data-tone="missing"><strong>${summary.missing}</strong><span>Missing</span></span>
                    <span class="crm-checklist-metric" data-tone="review"><strong>${summary.review}</strong><span>Need review</span></span>
                </div>
            </div>
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

    function renderChecklistGroupProgress(item) {
        const completeCount = (item.slots || []).filter((slot) => slot.is_complete).length;
        const requiredCount = item.required_count ?? (item.slots || []).length;

        return `${completeCount} / ${requiredCount} complete`;
    }

    function renderChecklistFileDetail(slot) {
        if (!slot.document) {
            return '<span class="crm-meta-text crm-checklist-empty">No file yet</span>';
        }

        const filename = escapeHtml(slot.document.original_filename);
        const detail = slot.detail ? escapeHtml(slot.detail) : '';

        if (detail) {
            return `
                <div class="crm-checklist-file-detail">
                    <div class="crm-table-primary">${filename}</div>
                    <div class="crm-meta-text">${detail}</div>
                </div>
            `;
        }

        return `<div class="crm-checklist-file-detail"><div class="crm-table-primary">${filename}</div></div>`;
    }

    function renderChecklistRowAction(slot) {
        if (slot.is_complete && slot.document) {
            return `<div class="crm-checklist-action-cell">${renderIconButton('preview', 'Preview document', `data-preview-document="${slot.document.id}"`)}</div>`;
        }

        if (slot.needs_review && slot.document) {
            return '<div class="crm-checklist-action-cell"><button type="button" class="crm-button crm-button--ghost crm-button--small" data-action="scroll-to-uploaded-files">Review</button></div>';
        }

        if (slot.is_missing) {
            return `<div class="crm-checklist-action-cell"><button type="button" class="crm-button crm-button--ghost crm-button--small" data-action="pick-documents" ${state.uploadingDocuments ? 'disabled' : ''}>Upload</button></div>`;
        }

        return '<div class="crm-checklist-action-cell"><span class="crm-meta-text">-</span></div>';
    }

    function renderChecklistTableRow(slot) {
        const tone = slot.is_complete ? 'matched' : slot.needs_review ? 'review' : slot.is_missing ? 'neutral' : 'stage';
        const status = slot.is_complete ? 'Complete' : slot.needs_review ? 'Needs review' : slot.is_missing ? 'Missing' : 'Pending';

        return `
            <tr class="crm-checklist-item-row">
                <td>
                    <div class="crm-checklist-indent">
                        <div class="crm-table-primary">${escapeHtml(slot.label)}</div>
                    </div>
                </td>
                <td><span class="crm-badge crm-badge--status" data-tone="${tone}">${status}</span></td>
                <td><div class="crm-checklist-indent">${renderChecklistFileDetail(slot)}</div></td>
                <td>${renderChecklistRowAction(slot)}</td>
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
        return (documents || []).filter((document) => !documentBlocksDeletion(document)).length;
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
        const deleteBlocked = documentBlocksDeletion(document);
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
        const aiStatusBadge = renderDocumentAuditBadge(document, extraction, aiStatusTone, aiStatusLabel);

        return `
            <tr class="crm-checklist-item-row">
                <td><input type="checkbox" data-document-select="${document.id}" ${isSelected ? 'checked' : ''} ${deleteBlocked ? 'disabled' : ''}></td>
                <td>
                    <div class="crm-checklist-indent">
                        <div class="crm-table-primary">${escapeHtml(document.original_filename || 'Uploaded document')}</div>
                    </div>
                </td>
                <td>${aiStatusBadge}</td>
                <td>
                    <select class="crm-select crm-select--compact" data-document-assignment="${document.id}" ${isActive ? 'disabled' : ''}>
                        ${renderChecklistAssignmentOptions(assignmentKey)}
                    </select>
                </td>
                <td>${escapeHtml(String(detail || 'N/A'))}</td>
                <td>${formatDateTime(document.uploaded_at)}</td>
                <td>${uploadStatus === 'deleting' ? '<span class="crm-meta-text">Removing...</span>' : `<div class="crm-inline">${renderIconButton('preview', 'Preview document', `data-preview-document="${document.id}"`)}${renderIconButton('delete', 'Remove document', `data-delete-document="${document.id}" ${deleteBlocked ? 'disabled' : ''}`, 'crm-button--danger-ghost')}</div>`}</td>
            </tr>
        `;
    }

    function renderDocumentAuditBadge(document, extraction, tone, label) {
        const badge = `<span class="crm-badge crm-badge--status" data-tone="${tone}">${escapeHtml(label)}</span>`;
        const audit = documentAuditSnapshot(document, extraction);

        if (!audit) {
            return badge;
        }

        return `
            <span class="crm-audit-popover" tabindex="0">
                ${badge}
                <span class="crm-audit-popover-card" role="tooltip" aria-label="AI detection audit details">
                    <strong class="crm-audit-popover-title">Detection Audit</strong>
                    <span class="crm-audit-popover-grid">
                        ${renderAuditMetric('Document Confidence', audit.documentConfidence)}
                        ${renderAuditMetric('Type Confidence', audit.classificationConfidence)}
                        ${renderAuditMetric('Side Confidence', audit.sideConfidence)}
                        ${renderAuditMetric('OCR Quality', audit.ocrQuality)}
                        ${renderAuditMetric('Field Completeness', audit.fieldCompleteness)}
                        ${renderAuditMetric('Score Margin', audit.scoreMargin)}
                    </span>
                    ${renderAuditMetricGroup('Strong Markers', audit.strongMarkers)}
                    ${renderAuditMetricGroup('Front Markers', audit.frontMarkers)}
                    ${renderAuditMetricGroup('Back Markers', audit.backMarkers)}
                    ${renderAuditMetricGroup('Contradictions', audit.contradictions)}
                    ${renderAuditMetricGroup('Review Reasons', audit.reviewReasons)}
                </span>
            </span>
        `;
    }

    function documentAuditSnapshot(document, extraction) {
        const classification = document?.classification || {};
        const structuredFields = extraction?.structured_fields || {};
        const providerMeta = structuredFields?.provider_meta || {};
        const evidence = providerMeta?.decision_evidence || {};
        const reviewReasons = Array.isArray(classification.review_reasons) && classification.review_reasons.length
            ? classification.review_reasons
            : Array.isArray(structuredFields.review_reasons)
                ? structuredFields.review_reasons
                : [];

        const hasAuditData = hasAuditValue(providerMeta.classification_confidence)
            || hasAuditValue(providerMeta.side_confidence)
            || hasAuditValue(providerMeta.ocr_quality)
            || hasAuditValue(providerMeta.field_completeness)
            || Array.isArray(evidence.strong_ic_markers)
            || Array.isArray(evidence.front_markers)
            || Array.isArray(evidence.back_markers)
            || Array.isArray(evidence.contradictory_evidence);

        if (!hasAuditData) {
            return null;
        }

        return {
            documentConfidence: classification.confidence || structuredFields.confidence || 'medium',
            classificationConfidence: providerMeta.classification_confidence || structuredFields.confidence || 'medium',
            sideConfidence: providerMeta.side_confidence || 'N/A',
            ocrQuality: providerMeta.ocr_quality || 'N/A',
            fieldCompleteness: providerMeta.field_completeness || 'N/A',
            scoreMargin: evidence.score_margin ?? 'N/A',
            strongMarkers: evidence.strong_ic_markers || [],
            frontMarkers: evidence.front_markers || [],
            backMarkers: evidence.back_markers || [],
            contradictions: evidence.contradictory_evidence || [],
            reviewReasons,
        };
    }

    function renderAuditMetric(label, value) {
        return `
            <span class="crm-audit-metric">
                <span class="crm-audit-metric-label">${escapeHtml(label)}</span>
                <span class="crm-audit-metric-value">${escapeHtml(formatAuditValue(value))}</span>
            </span>
        `;
    }

    function renderAuditMetricGroup(label, values) {
        const normalized = Array.isArray(values)
            ? values.filter((value) => value !== null && value !== undefined && String(value).trim() !== '')
            : [];

        if (!normalized.length) {
            return '';
        }

        return `
            <span class="crm-audit-group">
                <span class="crm-audit-group-label">${escapeHtml(label)}</span>
                <span class="crm-audit-group-value">${escapeHtml(normalized.map((value) => formatAuditValue(value)).join(', '))}</span>
            </span>
        `;
    }

    function formatAuditValue(value) {
        if (value === null || value === undefined || value === '') {
            return 'N/A';
        }

        if (typeof value === 'string') {
            return value.replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
        }

        return String(value);
    }

    function hasAuditValue(value) {
        return value !== null && value !== undefined && String(value).trim() !== '';
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
            pension_slip: 'Pension Slips',
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
            pension_slip: 35,
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

        document.querySelectorAll('[data-sort-field]').forEach((button) => {
            button.addEventListener('click', () => {
                toggleLeadSort(button.dataset.sortField);
            });
        });

        document.querySelectorAll('[data-view-lead]').forEach((element) => {
            if (element.classList.contains('crm-table-row-clickable')) {
                return;
            }

            element.addEventListener('click', async (event) => {
                event.stopPropagation();
                await loadLead(element.dataset.viewLead);
            });
        });

        document.querySelectorAll('.crm-table-row-clickable').forEach((row) => {
            row.addEventListener('click', async (event) => {
                if (event.target.closest('[data-stop-row-click], a, button')) {
                    return;
                }

                const leadId = row.dataset.viewLead;

                if (!leadId) {
                    return;
                }

                if (state.page === 'dashboard') {
                    window.location.href = `/workspace/leads/${leadId}`;
                    return;
                }

                await loadLead(leadId);
            });
        });

        document.querySelectorAll('[data-delete-lead]').forEach((element) => {
            element.addEventListener('click', async () => {
                await deleteLead(element.dataset.deleteLead);
            });
        });

        document.querySelector('.crm-confirm-card')?.addEventListener('click', (event) => {
            event.stopPropagation();
        });

        document.querySelectorAll('[data-action="confirm-cancel"]').forEach((element) => {
            element.addEventListener('click', (event) => {
                event.preventDefault();
                closeConfirmDialog();
            });
        });

        document.querySelector('[data-action="confirm-accept"]')?.addEventListener('click', async (event) => {
            event.preventDefault();
            await acceptConfirmDialog();
        });

        bindLeadModalEvents();
    }

    function bindLeadModalEvents() {
        document.querySelectorAll('[data-action="close-modal"]').forEach((element) => {
            bindOnce(element, 'CloseModal', 'click', (event) => {
                event.preventDefault();
                closeLeadModal();
            });
        });

        bindOnce(document.querySelector('.crm-modal-shell'), 'StopModalShell', 'click', (event) => {
            event.stopPropagation();
        });

        document.querySelectorAll('[data-action="pick-documents"]').forEach((button) => {
            bindOnce(button, 'PickDocuments', 'click', () => document.querySelector('#lead-document-input')?.click());
        });

        document.querySelectorAll('[data-action="scroll-to-uploaded-files"]').forEach((button) => {
            bindOnce(button, 'ScrollUploadedFiles', 'click', () => {
                document.querySelector('#crm-uploaded-files')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        bindOnce(document.querySelector('#lead-document-input'), 'LeadDocumentInput', 'change', async (event) => {
            await uploadLeadDocuments(event.currentTarget.files || []);
            event.currentTarget.value = '';
        });

        bindOnce(document.querySelector('[data-document-stage-dropzone]'), 'DocumentStageDragEnter', 'dragenter', (event) => {
            if (!transferHasFiles(event.dataTransfer)) {
                return;
            }

            event.preventDefault();
            documentStageDragDepth += 1;

            if (!state.documentStageDragActive) {
                state.documentStageDragActive = true;
                refreshLeadModalView();
            }
        });

        bindOnce(document.querySelector('[data-document-stage-dropzone]'), 'DocumentStageDragOver', 'dragover', (event) => {
            event.preventDefault();

            if (!state.documentStageDragActive) {
                state.documentStageDragActive = true;
                refreshLeadModalView();
            }
        });

        bindOnce(document.querySelector('[data-document-stage-dropzone]'), 'DocumentStageDragLeave', 'dragleave', (event) => {
            if (!transferHasFiles(event.dataTransfer)) {
                return;
            }

            event.preventDefault();
            documentStageDragDepth = Math.max(0, documentStageDragDepth - 1);

            if (documentStageDragDepth === 0 && state.documentStageDragActive) {
                state.documentStageDragActive = false;
                refreshLeadModalView();
            }
        });

        bindOnce(document.querySelector('[data-document-stage-dropzone]'), 'DocumentStageDrop', 'drop', async (event) => {
            event.preventDefault();
            documentStageDragDepth = 0;
            state.documentStageDragActive = false;
            await uploadLeadDocuments(event.dataTransfer?.files || []);
        });

        document.querySelectorAll('[data-document-assignment]').forEach((select) => {
            bindOnce(select, 'DocumentAssignment', 'change', async (event) => {
                await updateDocumentAssignment(select.dataset.documentAssignment, event.currentTarget.value);
            });
        });

        bindOnce(document.querySelector('[data-select-all-documents]'), 'SelectAllDocuments', 'change', (event) => {
            toggleAllDocumentSelections(event.currentTarget.checked);
        });

        bindOnce(document.querySelector('[data-select-all-toggle]'), 'SelectAllToggle', 'click', () => {
            const selectableCount = uploadedDocumentsSelectableCount(state.selectedLead?.documents || []);
            const shouldSelectAll = !(selectableCount && state.selectedDocumentIds.length === selectableCount);
            toggleAllDocumentSelections(shouldSelectAll);
        });

        document.querySelectorAll('[data-document-select]').forEach((input) => {
            bindOnce(input, 'DocumentSelect', 'change', (event) => {
                toggleDocumentSelection(input.dataset.documentSelect, event.currentTarget.checked);
            });
        });

        bindOnce(document.querySelector('[data-bulk-delete-documents]'), 'BulkDeleteDocuments', 'click', async () => {
            await bulkDeleteLeadDocuments();
        });

        document.querySelectorAll('[data-preview-document]').forEach((button) => {
            bindOnce(button, 'PreviewDocument', 'click', () => {
                previewLeadDocument(button.dataset.previewDocument);
            });
        });

        document.querySelectorAll('[data-delete-document]').forEach((button) => {
            bindOnce(button, 'DeleteDocument', 'click', async () => {
                await deleteLeadDocument(button.dataset.deleteDocument);
            });
        });

        document.querySelectorAll('[data-workflow-stage]').forEach((button) => {
            bindOnce(button, 'WorkflowStage', 'click', () => {
                setLeadWorkflowStage(button.dataset.workflowStage);
            });
        });

        bindOnce(document.querySelector('#calculation-form'), 'CalculationForm', 'submit', (event) => {
            event.preventDefault();
            runCalculation(event.currentTarget);
        });

        bindOnce(document.querySelector('[data-action="run-bank-match"]'), 'RunBankMatch', 'click', runBankMatch);
    }

    function bindGlobalWorkspaceEvents() {
        if (workspaceGlobalsBound) {
            return;
        }

        workspaceGlobalsBound = true;

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            if (state.confirmDialog) {
                closeConfirmDialog();
                return;
            }

            if (state.selectedLeadId) {
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
            pension_slip: 'Pension Slip',
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

    function formatLeadSourceDisplay(source) {
        if (!source) {
            return '—';
        }

        const separatorIndex = source.indexOf(' · ');
        if (separatorIndex !== -1) {
            const filename = source.slice(separatorIndex + 3).trim();
            return filename || source;
        }

        return source;
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
