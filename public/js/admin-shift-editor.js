(() => {
    'use strict';

    // 責務別モジュールを統括し、店舗別シフト編集の操作フローを管理します。
    const DEBOUNCE_MS = 700;
    const editor = document.querySelector('[data-shift-editor]');
    const modules = window.AdminShiftEditorModules;

    if (
        !editor
        || !modules?.request
        || !modules?.autosave
        || !modules?.view
        || !modules?.warning
        || !modules?.publication
    ) {
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const createUrl = editor.dataset.createShiftUrl;
    const shiftUrlTemplate = editor.dataset.shiftUrlTemplate;
    const targetMonth = editor.dataset.targetMonth;
    const saveStatus = editor.querySelector('[data-admin-save-status]');
    const initialSaveStatus = saveStatus?.textContent || '未変更';
    const modeStatus = editor.querySelector('[data-shift-mode-status]');
    const modeButtons = Array.from(editor.querySelectorAll('[data-shift-mode]'));
    const conflictNotice = editor.querySelector('[data-admin-conflict-notice]');
    const conflictReload = editor.querySelector('[data-admin-conflict-reload]');
    let draftVersion = parseDraftVersion(editor.dataset.draftVersion);
    let selectedMode = null;
    let allowConflictReload = false;

    if (
        !csrfToken
        || !createUrl
        || !shiftUrlTemplate
        || !targetMonth
        || draftVersion === null
    ) {
        return;
    }

    const {
        ConflictError,
        RequestError,
        createRequestJsonClient,
    } = modules.request;
    const {
        applyNormalizedPattern,
        applyRemainingSequences,
        applyShiftIdentity,
        markElementState,
        nextClientSequence,
        queueKey,
        recalculateSummaries,
        setModeStatus,
    } = modules.view.createShiftEditorView({editor, modeStatus});
    const requestJson = createRequestJsonClient({csrfToken});
    let queue = null;
    let warningPresenter = null;
    const publicationController = modules.publication.createShiftPublicationController({
        editor,
        requestJson,
        RequestError,
        ConflictError,
        getDraftVersion: () => draftVersion,
        hasUnsaved: () => queue?.hasUnsaved() ?? false,
        hasSchedule: () => Boolean(editor.dataset.shiftScheduleId),
        isStopped: () => queue?.isStopped() ?? false,
        onConflict: (error) => queue?.stopForConflict(error),
        applyWarningResult,
    });

    warningPresenter = modules.warning.createShiftWarningPresenter(editor, {
        onEligibilityChange: publicationController.updateEligibility,
    });
    queue = modules.autosave.createAutosaveQueue({
        debounceMs: DEBOUNCE_MS,
        onStateChange: updateSaveState,
        onConflict: enterConflictState,
        markElementState,
        ConflictError,
        RequestError,
    });
    publicationController.refresh();

    setupModeSelection();
    setupGridEditing();
    setupNavigationFlush();
    setupBeforeUnload();
    setupConflictRecovery();

    function setupModeSelection() {
        modeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (queue.isStopped()) {
                    return;
                }

                const isCurrent = button.getAttribute('aria-pressed') === 'true';

                modeButtons.forEach((candidate) => {
                    candidate.classList.remove('is-selected');
                    candidate.setAttribute('aria-pressed', 'false');
                });

                if (isCurrent) {
                    selectedMode = null;
                    setModeStatus('入力未選択');

                    return;
                }

                button.classList.add('is-selected');
                button.setAttribute('aria-pressed', 'true');
                selectedMode = button.dataset.shiftMode === 'delete'
                    ? {type: 'delete'}
                    : {
                        type: 'pattern',
                        patternId: Number(button.dataset.shiftPatternId),
                        patternCode: button.dataset.shiftPatternCode,
                        workHours: button.dataset.workHours,
                    };
                setModeStatus(
                    selectedMode.type === 'delete'
                        ? '削除モード'
                        : `${selectedMode.patternCode}入力モード`,
                );
            });
        });
    }

    function setupGridEditing() {
        editor.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }

            handleGridAction(event.target);
        });

        editor.addEventListener('keydown', (event) => {
            if (
                !['Enter', ' '].includes(event.key)
                || !(event.target instanceof Element)
                || !event.target.closest(
                    '[data-shift-editor-cell], .admin-shift-grid__shift-code',
                )
            ) {
                return;
            }

            event.preventDefault();
            handleGridAction(event.target);
        });
    }

    function handleGridAction(target) {
        if (queue.isStopped()) {
            return;
        }

        const cell = target.closest('[data-shift-editor-cell]');

        if (!cell || !selectedMode) {
            return;
        }

        const shift = target.closest('.admin-shift-grid__shift-code');

        if (selectedMode.type === 'delete') {
            if (shift) {
                requestDelete(shift);
            }

            return;
        }

        if (shift) {
            requestPatternChange(shift, selectedMode);

            return;
        }

        requestCreate(cell, selectedMode);
    }

    function requestCreate(cell, pattern) {
        const shift = document.createElement('span');
        const entryUuid = newEntryUuid();
        const sequence = nextClientSequence(cell);

        shift.className = 'admin-shift-grid__shift-code';
        shift.tabIndex = 0;
        shift.setAttribute('role', 'button');
        shift.dataset.userId = cell.dataset.userId;
        shift.dataset.storeId = cell.dataset.storeId;
        shift.dataset.shiftDate = cell.dataset.shiftDate;
        shift.dataset.shiftId = '';
        shift.dataset.entryUuid = entryUuid;
        shift.dataset.sequence = String(sequence);
        shift.dataset.shiftPatternId = String(pattern.patternId);
        shift.dataset.workHours = pattern.workHours;
        shift.dataset.queueKey = `entry:${entryUuid}`;
        shift.textContent = pattern.patternCode;
        cell.appendChild(shift);

        recalculateSummaries();
        enqueueSave(shift, pattern);
    }

    function requestPatternChange(shift, pattern) {
        const samePattern = shift.dataset.shiftPatternId === String(pattern.patternId);
        const failed = shift.dataset.saveState === 'failed';

        if (samePattern && !failed) {
            return;
        }

        shift.dataset.shiftPatternId = String(pattern.patternId);
        shift.dataset.workHours = pattern.workHours;
        shift.textContent = pattern.patternCode;

        recalculateSummaries();
        enqueueSave(shift, pattern);
    }

    function enqueueSave(shift, pattern) {
        const key = queueKey(shift);

        queue.enqueue(key, {
            element: shift,
            type: 'save',
            patternId: pattern.patternId,
            execute: () => persistShift(shift, pattern.patternId),
            onSuccess: (payload) => {
                applyShiftIdentity(shift, payload);

                if (shift.dataset.shiftPatternId === String(pattern.patternId)) {
                    applyNormalizedPattern(shift, payload);
                }

                updateScheduleIdentity(payload);
                applyWarningResult(payload.warning_result);
                markElementState(shift, 'saved');
                recalculateSummaries();
            },
            onFailure: (message) => markElementState(shift, 'failed', message),
        });
    }

    function requestDelete(shift) {
        const key = queueKey(shift);

        if (!shift.dataset.shiftId && queue.cancelPending(key)) {
            shift.remove();
            recalculateSummaries();

            return;
        }

        queue.enqueue(key, {
            element: shift,
            type: 'delete',
            execute: () => deleteShift(shift),
            onSuccess: (payload) => {
                shift.remove();
                applyRemainingSequences(payload.remaining_shifts || []);
                updateScheduleIdentity(payload);
                applyWarningResult(payload.warning_result);
                recalculateSummaries();
            },
            onFailure: (message) => markElementState(shift, 'failed', message),
        });
    }

    async function persistShift(shift, patternId) {
        if (shift.dataset.shiftId) {
            return requestJson(shiftUrl(shift.dataset.shiftId), {
                method: 'PATCH',
                body: {
                    target_month: targetMonth,
                    shift_pattern_id: patternId,
                    expected_draft_version: draftVersion,
                },
            });
        }

        return requestJson(createUrl, {
            method: 'POST',
            body: {
                target_month: targetMonth,
                user_id: Number(shift.dataset.userId),
                work_date: shift.dataset.shiftDate,
                shift_pattern_id: patternId,
                entry_uuid: shift.dataset.entryUuid,
                expected_draft_version: draftVersion,
            },
        });
    }

    async function deleteShift(shift) {
        if (!shift.dataset.shiftId) {
            const created = await persistShift(
                shift,
                Number(shift.dataset.shiftPatternId),
            );

            applyShiftIdentity(shift, created);
            updateScheduleIdentity(created);
        }

        return requestJson(shiftUrl(shift.dataset.shiftId), {
            method: 'DELETE',
            body: {
                target_month: targetMonth,
                expected_draft_version: draftVersion,
            },
        });
    }

    function updateSaveState(state, failedMessage = '') {
        const labels = {
            idle: initialSaveStatus,
            pending: '保存待ち',
            saving: '保存中',
            saved: '保存済み',
            failed: '保存失敗',
            conflict: '競合',
        };

        if (saveStatus) {
            saveStatus.dataset.saveState = state;
            saveStatus.textContent = labels[state];
            saveStatus.title = ['failed', 'conflict'].includes(state)
                ? failedMessage
                : '';
        }

        document.dispatchEvent(new CustomEvent('admin-shift:autosave-state', {
            detail: {
                state,
                month: targetMonth,
                message: failedMessage,
            },
        }));
        publicationController.updateAutosaveState(state);
    }

    function setupNavigationFlush() {
        document.addEventListener('admin-shift:autosave-flush-request', (event) => {
            if (event.detail?.month !== targetMonth) {
                return;
            }

            queue.flush();
        });
    }

    function setupBeforeUnload() {
        window.addEventListener('beforeunload', (event) => {
            if (allowConflictReload || !queue.hasUnsaved()) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        });
    }

    function updateScheduleIdentity(payload) {
        if (payload.shift_schedule_id) {
            editor.dataset.shiftScheduleId = String(payload.shift_schedule_id);
        }

        const responseVersion = parseDraftVersion(payload.draft_version);

        // 正常応答でも、保持中のバージョンを巻き戻す値は適用しません。
        if (responseVersion !== null && responseVersion >= draftVersion) {
            draftVersion = responseVersion;
            editor.dataset.draftVersion = String(responseVersion);
            publicationController.updateDraftVersion(responseVersion);
        }
    }

    function applyWarningResult(result) {
        if (!result || queue.isStopped()) {
            return;
        }

        const checkedVersion = parseDraftVersion(result.checked_draft_version);

        // 保存済み下書きと同じ版を検査した警告だけを現在のDOMへ適用します。
        if (checkedVersion === null || checkedVersion !== draftVersion) {
            return;
        }

        warningPresenter.apply(result);
    }

    function setupConflictRecovery() {
        conflictReload?.addEventListener('click', () => {
            const confirmed = window.confirm(
                '再読み込みすると、この画面に残っている未保存の入力は失われます。'
                + '最新のシフトを読み込みますか？',
            );

            if (!confirmed) {
                return;
            }

            allowConflictReload = true;
            window.location.reload();
        });
    }

    function enterConflictState(message) {
        editor.dataset.conflictState = 'true';
        selectedMode = null;
        modeButtons.forEach((button) => {
            button.disabled = true;
            button.classList.remove('is-selected');
            button.setAttribute('aria-pressed', 'false');
        });
        editor.querySelectorAll(
            '[data-shift-editor-cell], .admin-shift-grid__shift-code',
        ).forEach((element) => {
            element.setAttribute('aria-disabled', 'true');
            element.tabIndex = -1;
        });
        setModeStatus('競合のため編集停止');

        if (conflictNotice) {
            const description = conflictNotice.querySelector('span');

            if (description && message) {
                description.textContent = message;
            }

            conflictNotice.hidden = false;
        }
    }

    function shiftUrl(shiftId) {
        return shiftUrlTemplate.replace(
            '__SHIFT_ID__',
            encodeURIComponent(shiftId),
        );
    }

    function newEntryUuid() {
        if (typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }

        const bytes = crypto.getRandomValues(new Uint8Array(16));

        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;

        const hex = Array.from(
            bytes,
            (byte) => byte.toString(16).padStart(2, '0'),
        );

        return [
            hex.slice(0, 4).join(''),
            hex.slice(4, 6).join(''),
            hex.slice(6, 8).join(''),
            hex.slice(8, 10).join(''),
            hex.slice(10, 16).join(''),
        ].join('-');
    }

    function parseDraftVersion(value) {
        const version = Number(value);

        return Number.isSafeInteger(version) && version >= 0 ? version : null;
    }
})();
