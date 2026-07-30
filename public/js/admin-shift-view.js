(() => {
    'use strict';

    function createShiftEditorView({editor, modeStatus}) {
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
            element.classList.remove(
                'is-save-pending',
                'is-saving',
                'is-save-failed',
            );
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

                setText(
                    row.querySelector('[data-row-summary="time"]'),
                    formatMinutes(rowTotals.minutes),
                );
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

        function setModeStatus(message) {
            if (modeStatus) {
                modeStatus.textContent = message;
            }
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

        return Object.freeze({
            applyNormalizedPattern,
            applyRemainingSequences,
            applyShiftIdentity,
            markElementState,
            nextClientSequence,
            queueKey,
            recalculateSummaries,
            setModeStatus,
        });
    }

    const modules = window.AdminShiftEditorModules
        || (window.AdminShiftEditorModules = {});

    modules.view = Object.freeze({
        createShiftEditorView,
    });
})();
