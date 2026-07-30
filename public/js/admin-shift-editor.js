(() => {
    'use strict';

    const DEBOUNCE_MS = 700;
    const editor = document.querySelector('[data-shift-editor]');

    if (!editor) {
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
    const warningPanel = editor.querySelector('[data-admin-warning-panel]');
    const warningList = editor.querySelector('[data-admin-warning-list]');
    const warningCount = editor.querySelector('[data-admin-warning-count]');
    const publishEligibility = editor.querySelector(
        '[data-admin-publish-eligibility]',
    );
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

    const queue = createAutosaveQueue({
        debounceMs: DEBOUNCE_MS,
        onStateChange: updateSaveState,
        onConflict: enterConflictState,
    });

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
                        workMinutes: Number(button.dataset.workMinutes),
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
        shift.dataset.workMinutes = String(pattern.workMinutes);
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
        shift.dataset.workMinutes = String(pattern.workMinutes);
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

    async function requestJson(url, options) {
        let response;

        try {
            response = await fetch(url, {
                method: options.method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(options.body),
            });
        } catch (error) {
            throw new RequestError('通信できませんでした。入力内容を残したまま再試行できます。');
        }

        const payload = await response.json().catch(() => ({}));

        if (response.ok) {
            return payload;
        }

        if (response.status === 401) {
            window.location.assign('/login');
            throw new RequestError('認証の有効期限が切れました。再度ログインしてください。');
        }

        if (response.status === 422) {
            const validationMessage = Object.values(payload.errors || {})
                .flat()
                .find((message) => typeof message === 'string');

            throw new RequestError(
                validationMessage || '入力内容を確認してください。',
            );
        }

        if (response.status === 409) {
            throw new ConflictError(
                payload.message
                    || '別の画面で更新されました。再読み込みしてください。',
                payload,
            );
        }

        if (response.status === 403) {
            throw new RequestError('この店舗のシフトは変更できません。');
        }

        if (response.status === 404) {
            throw new RequestError('対象のシフトが見つかりません。画面を再読み込みしてください。');
        }

        throw new RequestError('保存できませんでした。時間をおいて再試行してください。');
    }

    function createAutosaveQueue({debounceMs, onStateChange, onConflict}) {
        const items = new Map();
        let hasCompletedSave = false;
        let networkBusy = false;
        let stopped = false;
        let nextOperationId = 1;
        let lastAppliedOperationId = 0;

        function enqueue(key, operation) {
            if (stopped) {
                return;
            }

            const item = items.get(key) || {
                pending: null,
                saving: false,
                failed: false,
                timer: null,
                ready: false,
            };

            if (item.timer) {
                window.clearTimeout(item.timer);
            }

            item.pending = {
                ...operation,
                operationId: nextOperationId++,
            };
            item.failed = false;
            item.ready = false;
            item.timer = window.setTimeout(() => {
                item.timer = null;
                item.ready = true;
                drainNext();
            }, debounceMs);
            items.set(key, item);
            markElementState(operation.element, 'pending');
            publishState();
        }

        async function drainNext() {
            if (networkBusy || stopped) {
                return;
            }

            const next = Array.from(items.entries())
                .filter(([, item]) => item.ready && !item.saving && item.pending)
                .sort(
                    ([, left], [, right]) => left.pending.operationId
                        - right.pending.operationId,
                )
                .at(0);

            if (!next) {
                return;
            }

            const [key, item] = next;
            const operation = item.pending;

            item.pending = null;
            item.ready = false;
            item.saving = true;
            item.failed = false;
            networkBusy = true;
            markElementState(operation.element, 'saving');
            publishState();

            try {
                const payload = await operation.execute();

                if (stopped || operation.operationId <= lastAppliedOperationId) {
                    item.saving = false;
                    networkBusy = false;
                    publishState();

                    return;
                }

                lastAppliedOperationId = operation.operationId;
                operation.onSuccess(payload);
                item.saving = false;
                networkBusy = false;
                hasCompletedSave = true;

                if (item.pending) {
                    publishState();
                    drainNext();

                    return;
                }

                items.delete(key);
                publishState();
                drainNext();
            } catch (error) {
                item.saving = false;
                networkBusy = false;

                if (error instanceof ConflictError) {
                    stopForConflict(error);

                    return;
                }

                if (item.pending) {
                    publishState();
                    drainNext();

                    return;
                }

                item.failed = true;
                item.failedMessage = error instanceof RequestError
                    ? error.message
                    : '保存できませんでした。';
                operation.onFailure(item.failedMessage);
                publishState();
                drainNext();
            }
        }

        function stopForConflict(error) {
            stopped = true;
            items.forEach((item) => {
                if (item.timer) {
                    window.clearTimeout(item.timer);
                    item.timer = null;
                }

                item.ready = false;
            });
            onConflict(error.message, error.payload);
            publishState();
        }

        function cancelPending(key) {
            const item = items.get(key);

            if (!item || item.saving || item.failed) {
                return false;
            }

            if (item.timer) {
                window.clearTimeout(item.timer);
            }

            items.delete(key);
            publishState();
            drainNext();

            return true;
        }

        function flush() {
            items.forEach((item, key) => {
                if (item.timer) {
                    window.clearTimeout(item.timer);
                    item.timer = null;
                }

                if (item.pending && !item.saving) {
                    item.ready = true;
                }
            });

            publishState();
            drainNext();
        }

        function state() {
            if (stopped) {
                return 'conflict';
            }

            if (Array.from(items.values()).some((item) => item.failed)) {
                return 'failed';
            }

            if (Array.from(items.values()).some((item) => item.saving)) {
                return 'saving';
            }

            if (Array.from(items.values()).some((item) => item.pending || item.timer)) {
                return 'pending';
            }

            return hasCompletedSave ? 'saved' : 'idle';
        }

        function publishState() {
            const currentState = state();
            const failedMessage = Array.from(items.values())
                .find((item) => item.failed)?.failedMessage;

            onStateChange(currentState, failedMessage);
        }

        return {
            enqueue,
            cancelPending,
            flush,
            hasUnsaved: () => stopped || items.size > 0,
            isStopped: () => stopped,
        };
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

    function applyShiftIdentity(shift, payload) {
        shift.dataset.shiftId = String(payload.shift_id);
        shift.dataset.entryUuid = payload.entry_uuid;
        shift.dataset.sequence = String(payload.sequence);
    }

    function applyNormalizedPattern(shift, payload) {
        shift.dataset.shiftPatternId = String(payload.shift_pattern_id);
        shift.dataset.workMinutes = String(payload.work_minutes);
        shift.textContent = payload.pattern_code;
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

        const warnings = Array.isArray(result.warnings) ? result.warnings : [];
        const cells = Array.from(editor.querySelectorAll(
            '.admin-shift-grid__shift-cell[data-user-id][data-store-id][data-shift-date]',
        ));
        const warningsByCell = new Map();

        cells.forEach((cell) => {
            cell.classList.remove('is-warning');
            cell.dataset.warningCodes = '';
            const baseLabel = cell.dataset.cellLabel;

            if (baseLabel) {
                cell.setAttribute('aria-label', baseLabel);
            }
        });

        warnings.forEach((warning) => {
            cells
                .filter((cell) => warningMatchesCell(warning, cell))
                .forEach((cell) => {
                    const cellWarnings = warningsByCell.get(cell) || [];

                    cellWarnings.push(warning);
                    warningsByCell.set(cell, cellWarnings);
                });
        });

        warningsByCell.forEach((cellWarnings, cell) => {
            const codes = Array.from(new Set(
                cellWarnings.map((warning) => warning.warning_code),
            ));
            const messages = cellWarnings
                .map((warning) => warning.message)
                .filter((message) => typeof message === 'string');

            cell.classList.add('is-warning');
            cell.dataset.warningCodes = codes.join(',');
            cell.setAttribute(
                'aria-label',
                `${cell.dataset.cellLabel || ''}。警告：${messages.join(' ')}`,
            );
        });

        updateDailyWarningStatuses(warnings);
        renderWarningPanel(result, warnings);
    }

    function warningMatchesCell(warning, cell) {
        if (warning.work_date !== cell.dataset.shiftDate) {
            return false;
        }

        const storeIds = Array.isArray(warning.store_ids)
            ? warning.store_ids.map(String)
            : [String(warning.store_id || '')];

        if (!storeIds.includes(cell.dataset.storeId)) {
            return false;
        }

        return warning.user_id === undefined
            || String(warning.user_id) === cell.dataset.userId;
    }

    function updateDailyWarningStatuses(warnings) {
        editor.querySelectorAll('[data-admin-daily-status]').forEach((status) => {
            const hasWarning = warnings.some(
                (warning) => warning.work_date === status.dataset.shiftDate,
            );
            const mark = status.querySelector('span');

            status.classList.add('is-active');
            status.classList.toggle('is-warning', hasWarning);

            if (mark) {
                mark.textContent = hasWarning ? '×' : '○';
                mark.setAttribute(
                    'aria-label',
                    hasWarning ? '確認不合格' : '確認済み',
                );
            }
        });
    }

    function renderWarningPanel(result, warnings) {
        const canPublish = result.can_publish === true;
        const count = Number(result.blocking_warning_count) || 0;

        if (warningPanel) {
            warningPanel.classList.toggle('is-clear', canPublish);
            warningPanel.dataset.checkedDraftVersion = String(
                result.checked_draft_version,
            );
        }

        if (publishEligibility) {
            publishEligibility.textContent = canPublish ? '配布可能' : '配布不可';
        }

        if (warningCount) {
            warningCount.textContent = `警告 ${count}件`;
        }

        if (warningList) {
            warningList.replaceChildren();
            warningList.hidden = warnings.length === 0;

            warnings.forEach((warning) => {
                const item = document.createElement('li');
                const icon = document.createElement('span');

                item.dataset.warningCode = warning.warning_code || '';
                item.dataset.warningDate = warning.work_date || '';
                item.setAttribute(
                    'aria-label',
                    `警告：${warning.message || ''}`,
                );
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = '⚠';
                item.append(icon, document.createTextNode(
                    ` ${warning.message || ''}`,
                ));
                warningList.appendChild(item);
            });
        }

        const publishNote = editor.querySelector('.admin-publish-note');

        if (publishNote) {
            publishNote.textContent = canPublish
                ? '配布可能'
                : `配布不可（警告${count}件）`;
            publishNote.classList.toggle('is-warning', !canPublish);
        }

        const publishButton = editor.querySelector('.admin-publish-button');

        if (publishButton) {
            publishButton.title = canPublish
                ? '配布処理は次の段階で実装します'
                : '警告を解消するまで配布できません';
        }
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

    function applyRemainingSequences(shifts) {
        shifts.forEach((payload) => {
            const shift = editor.querySelector(
                `.admin-shift-grid__shift-code[data-shift-id="${payload.shift_id}"]`,
            );

            if (!shift) {
                return;
            }

            shift.dataset.sequence = String(payload.sequence);
            applyNormalizedPattern(shift, payload);
            markElementState(shift, 'saved');
        });
    }

    function markElementState(element, state, message = '') {
        element.classList.remove('is-save-pending', 'is-saving', 'is-save-failed');
        element.dataset.saveState = state;

        if (state === 'pending') {
            element.classList.add('is-save-pending');
        } else if (state === 'saving') {
            element.classList.add('is-saving');
        } else if (state === 'failed') {
            element.classList.add('is-save-failed');
        }

        element.title = state === 'failed' ? message : '';
    }

    function recalculateSummaries() {
        const totals = {
            minutes: 0,
            count: 0,
            codes: {A: 0, B: 0, C: 0, D: 0, E: 0},
        };

        editor.querySelectorAll('tbody tr[data-user-id]').forEach((row) => {
            const rowTotals = {
                minutes: 0,
                codes: {A: 0, B: 0, C: 0, D: 0, E: 0},
            };
            const shifts = Array.from(
                row.querySelectorAll('.admin-shift-grid__shift-code'),
            );

            shifts.forEach((shift) => {
                const minutes = Number(shift.dataset.workMinutes) || 0;
                const code = shift.textContent.trim();

                rowTotals.minutes += minutes;
                totals.minutes += minutes;
                totals.count += 1;

                if (Object.hasOwn(rowTotals.codes, code)) {
                    rowTotals.codes[code] += 1;
                    totals.codes[code] += 1;
                }
            });

            setText(row.querySelector('[data-row-summary="time"]'), formatMinutes(
                rowTotals.minutes,
            ));
            Object.entries(rowTotals.codes).forEach(([code, count]) => {
                setText(
                    row.querySelector(`[data-row-summary-code="${code}"]`),
                    count || '',
                );
            });
        });

        setText(
            editor.querySelector('[data-grid-summary="time"]'),
            formatMinutes(totals.minutes),
        );
        setText(
            editor.querySelector('[data-grid-summary="total"]'),
            totals.count,
        );
        Object.entries(totals.codes).forEach(([code, count]) => {
            setText(
                editor.querySelector(`[data-grid-summary-code="${code}"]`),
                count,
            );
        });
    }

    function setText(element, value) {
        if (element) {
            element.textContent = String(value);
        }
    }

    function formatMinutes(minutes) {
        const hours = Math.floor(minutes / 60);
        const remainder = minutes % 60;

        return `${hours}:${String(remainder).padStart(2, '0')}`;
    }

    function nextClientSequence(cell) {
        return Array.from(cell.querySelectorAll('[data-sequence]'))
            .reduce(
                (maximum, shift) => Math.max(
                    maximum,
                    Number(shift.dataset.sequence) || 0,
                ),
                0,
            ) + 1;
    }

    function queueKey(shift) {
        if (!shift.dataset.queueKey) {
            shift.dataset.queueKey = shift.dataset.shiftId
                ? `shift:${shift.dataset.shiftId}`
                : `entry:${shift.dataset.entryUuid}`;
        }

        return shift.dataset.queueKey;
    }

    function shiftUrl(shiftId) {
        return shiftUrlTemplate.replace(
            '__SHIFT_ID__',
            encodeURIComponent(shiftId),
        );
    }

    function setModeStatus(message) {
        if (modeStatus) {
            modeStatus.textContent = message;
        }
    }

    function newEntryUuid() {
        if (typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }

        const bytes = crypto.getRandomValues(new Uint8Array(16));

        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;

        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0'));

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

    class RequestError extends Error {}

    class ConflictError extends RequestError {
        constructor(message, payload) {
            super(message);
            this.payload = payload;
        }
    }
})();
