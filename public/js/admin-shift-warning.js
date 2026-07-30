(() => {
    'use strict';

    function createShiftWarningPresenter(
        editor,
        {onEligibilityChange = () => {}} = {},
    ) {
        const warningPanel = editor.querySelector('[data-admin-warning-panel]');
        const warningList = editor.querySelector('[data-admin-warning-list]');
        const warningCount = editor.querySelector('[data-admin-warning-count]');
        const publishEligibility = editor.querySelector(
            '[data-admin-publish-eligibility]',
        );

        function apply(result) {
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
                publishEligibility.textContent = canPublish
                    ? '配布可能'
                    : '配布不可';
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

            onEligibilityChange(canPublish, count);
        }

        return Object.freeze({apply});
    }

    const modules = window.AdminShiftEditorModules
        || (window.AdminShiftEditorModules = {});

    modules.warning = Object.freeze({
        createShiftWarningPresenter,
    });
})();
