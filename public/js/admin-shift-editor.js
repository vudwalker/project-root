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
    let selectedMode = null;

    if (!csrfToken || !createUrl || !shiftUrlTemplate || !targetMonth) {
        return;
    }

    const queue = createAutosaveQueue({
        debounceMs: DEBOUNCE_MS,
        onStateChange: updateSaveState,
    });

    setupModeSelection();
    setupGridEditing();
    setupNavigationFlush();
    setupBeforeUnload();

    function setupModeSelection() {
        modeButtons.forEach((button) => {
            button.addEventListener('click', () => {
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

        if (response.status === 403) {
            throw new RequestError('この店舗のシフトは変更できません。');
        }

        if (response.status === 404) {
            throw new RequestError('対象のシフトが見つかりません。画面を再読み込みしてください。');
        }

        throw new RequestError('保存できませんでした。時間をおいて再試行してください。');
    }

    function createAutosaveQueue({debounceMs, onStateChange}) {
        const items = new Map();
        let hasCompletedSave = false;

        function enqueue(key, operation) {
            const item = items.get(key) || {
                pending: null,
                saving: false,
                failed: false,
                timer: null,
            };

            if (item.timer) {
                window.clearTimeout(item.timer);
            }

            item.pending = operation;
            item.failed = false;
            item.timer = window.setTimeout(() => {
                item.timer = null;
                drain(key);
            }, debounceMs);
            items.set(key, item);
            markElementState(operation.element, 'pending');
            publishState();
        }

        async function drain(key) {
            const item = items.get(key);

            if (!item || item.saving || !item.pending) {
                return;
            }

            const operation = item.pending;

            item.pending = null;
            item.saving = true;
            item.failed = false;
            markElementState(operation.element, 'saving');
            publishState();

            try {
                const payload = await operation.execute();

                operation.onSuccess(payload);
                item.saving = false;
                hasCompletedSave = true;

                if (item.pending) {
                    publishState();
                    drain(key);

                    return;
                }

                items.delete(key);
                publishState();
            } catch (error) {
                item.saving = false;

                if (item.pending) {
                    publishState();
                    drain(key);

                    return;
                }

                item.failed = true;
                item.failedMessage = error instanceof RequestError
                    ? error.message
                    : '保存できませんでした。';
                operation.onFailure(item.failedMessage);
                publishState();
            }
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

            return true;
        }

        function flush() {
            items.forEach((item, key) => {
                if (item.timer) {
                    window.clearTimeout(item.timer);
                    item.timer = null;
                }

                if (item.pending && !item.saving) {
                    drain(key);
                }
            });

            publishState();
        }

        function state() {
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
            hasUnsaved: () => items.size > 0,
        };
    }

    function updateSaveState(state, failedMessage = '') {
        const labels = {
            idle: initialSaveStatus,
            pending: '保存待ち',
            saving: '保存中',
            saved: '保存済み',
            failed: '保存失敗',
        };

        if (saveStatus) {
            saveStatus.dataset.saveState = state;
            saveStatus.textContent = labels[state];
            saveStatus.title = state === 'failed' ? failedMessage : '';
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
            if (!queue.hasUnsaved()) {
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

    class RequestError extends Error {}
})();
