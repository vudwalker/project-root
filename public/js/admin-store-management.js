(() => {
    'use strict';

    const form = document.querySelector('[data-store-detail-form]');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const saveButton = form.querySelector('[data-store-save-button]');
    const saveStatus = form.querySelector('[data-store-save-status]');
    const userRowTemplate = document.querySelector('[data-user-row-template]');
    const patternRowTemplate = document.querySelector('[data-pattern-row-template]');
    let initialState = '';
    let isSubmitting = false;

    const normalizePatternNames = () => {
        form.querySelectorAll('[data-pattern-row]').forEach((row, index) => {
            row.querySelectorAll('[data-pattern-field]').forEach((field) => {
                if (!(field instanceof HTMLInputElement)) {
                    return;
                }

                const key = field.dataset.patternField;

                if (key) {
                    field.name = `patterns[${index}][${key}]`;
                }
            });
        });
    };

    const formState = () => {
        normalizePatternNames();

        return new URLSearchParams(new FormData(form)).toString();
    };

    const refreshDirtyState = () => {
        const isDirty = formState() !== initialState;

        if (saveButton instanceof HTMLButtonElement) {
            saveButton.disabled = !isDirty || isSubmitting;
        }

        if (saveStatus instanceof HTMLElement) {
            saveStatus.textContent = isDirty ? '未保存の変更があります' : '変更はありません';
        }
    };

    const selectedIds = (kind) => new Set(
        Array.from(
            form.querySelectorAll(
                `[data-selected-users="${kind}"] [data-selected-user]`,
            ),
        ).map((row) => String(row.dataset.userId)),
    );

    const refreshEmptyUserRow = (kind) => {
        const tbody = form.querySelector(`[data-selected-users="${kind}"]`);
        const emptyRow = form.querySelector(`[data-empty-selected-users="${kind}"]`);

        if (!(tbody instanceof HTMLTableSectionElement) || !(emptyRow instanceof HTMLTableRowElement)) {
            return;
        }

        emptyRow.hidden = tbody.querySelector('[data-selected-user]') !== null;
    };

    const addSelectedUser = (kind, user) => {
        const tbody = form.querySelector(`[data-selected-users="${kind}"]`);

        if (
            !(tbody instanceof HTMLTableSectionElement)
            || !(userRowTemplate instanceof HTMLTemplateElement)
            || selectedIds(kind).has(String(user.id))
        ) {
            return;
        }

        const fragment = userRowTemplate.content.cloneNode(true);
        const row = fragment.querySelector('[data-selected-user]');
        const name = fragment.querySelector('[data-user-name]');
        const email = fragment.querySelector('[data-user-email]');
        const idInput = fragment.querySelector('[data-user-id-input]');
        const removeButton = fragment.querySelector('[data-remove-selected-user]');

        if (
            !(row instanceof HTMLTableRowElement)
            || !(name instanceof HTMLElement)
            || !(email instanceof HTMLElement)
            || !(idInput instanceof HTMLInputElement)
            || !(removeButton instanceof HTMLButtonElement)
        ) {
            return;
        }

        row.dataset.userId = String(user.id);
        name.textContent = String(user.name);
        email.textContent = String(user.email);
        idInput.name = kind === 'staff'
            ? 'staff_user_ids[]'
            : 'manager_user_ids[]';
        idInput.value = String(user.id);
        removeButton.textContent = kind === 'staff' ? '所属解除' : '担当解除';

        tbody.insertBefore(fragment, tbody.querySelector('[data-empty-selected-users]'));
        refreshEmptyUserRow(kind);
        refreshDirtyState();
    };

    const renderCandidates = (panel, kind, candidates) => {
        const results = panel.querySelector('[data-candidate-results]');
        const message = panel.querySelector('[data-candidate-message]');

        if (!(results instanceof HTMLElement) || !(message instanceof HTMLElement)) {
            return;
        }

        results.replaceChildren();
        const selected = selectedIds(kind);
        const available = candidates.filter(
            (candidate) => !selected.has(String(candidate.id)),
        );

        if (available.length === 0) {
            message.textContent = '追加できる候補はありません。';

            return;
        }

        message.textContent = `${available.length}件見つかりました。`;

        available.forEach((candidate) => {
            const row = document.createElement('div');
            const identity = document.createElement('span');
            const button = document.createElement('button');

            row.className = 'admin-store-candidate-result';
            identity.textContent = `${candidate.name}（${candidate.email}）`;
            button.className = 'admin-flat-button';
            button.type = 'button';
            button.textContent = '追加';
            button.addEventListener('click', () => {
                addSelectedUser(kind, candidate);
                row.remove();

                if (results.children.length === 0) {
                    message.textContent = '追加できる候補はありません。';
                }
            });
            row.append(identity, button);
            results.append(row);
        });
    };

    const searchCandidates = async (panel, kind) => {
        const query = panel.querySelector('[data-candidate-query]');
        const message = panel.querySelector('[data-candidate-message]');
        const results = panel.querySelector('[data-candidate-results]');
        const url = kind === 'staff'
            ? form.dataset.staffCandidatesUrl
            : form.dataset.managerCandidatesUrl;

        if (
            !(query instanceof HTMLInputElement)
            || !(message instanceof HTMLElement)
            || !(results instanceof HTMLElement)
            || !url
        ) {
            return;
        }

        const term = query.value.trim();

        if (term === '') {
            results.replaceChildren();
            message.textContent = '検索語を入力してください。';
            query.focus();

            return;
        }

        message.textContent = '検索中です…';
        results.replaceChildren();

        try {
            const response = await fetch(`${url}?q=${encodeURIComponent(term)}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('candidate search failed');
            }

            const payload = await response.json();
            renderCandidates(
                panel,
                kind,
                Array.isArray(payload.data) ? payload.data : [],
            );
        } catch (_error) {
            message.textContent = '検索に失敗しました。時間をおいて再度お試しください。';
        }
    };

    form.querySelectorAll('[data-candidate-panel-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const kind = button.dataset.candidatePanelToggle;
            const panel = form.querySelector(`[data-candidate-panel="${kind}"]`);

            if (!(panel instanceof HTMLElement)) {
                return;
            }

            panel.hidden = !panel.hidden;
            button.setAttribute('aria-expanded', String(!panel.hidden));

            if (!panel.hidden) {
                panel.querySelector('[data-candidate-query]')?.focus();
            }
        });
    });

    form.querySelectorAll('[data-candidate-panel]').forEach((panel) => {
        const kind = panel.dataset.candidatePanel;
        const searchButton = panel.querySelector('[data-candidate-search]');
        const query = panel.querySelector('[data-candidate-query]');

        searchButton?.addEventListener('click', () => {
            searchCandidates(panel, kind);
        });
        query?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchCandidates(panel, kind);
            }
        });
    });

    form.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        const removeUserButton = target.closest('[data-remove-selected-user]');

        if (removeUserButton) {
            const row = removeUserButton.closest('[data-selected-user]');
            const tbody = row?.closest('[data-selected-users]');
            const kind = tbody?.dataset.selectedUsers;

            row?.remove();

            if (kind) {
                refreshEmptyUserRow(kind);
            }

            refreshDirtyState();

            return;
        }

        const removePatternButton = target.closest('[data-remove-pattern]');

        if (removePatternButton) {
            const patternRow = removePatternButton.closest('[data-pattern-row]');
            const patternId = patternRow
                ?.querySelector('[data-pattern-field="id"]')
                ?.value;

            patternRow?.remove();

            if (patternId) {
                form.querySelectorAll('[data-staffing-pattern-column]').forEach(
                    (column) => {
                        if (column.dataset.staffingPatternColumn === patternId) {
                            column.remove();
                        }
                    },
                );
            }

            const emptyRow = form.querySelector('[data-empty-patterns]');

            if (emptyRow instanceof HTMLTableRowElement) {
                emptyRow.hidden = form.querySelector('[data-pattern-row]') !== null;
            }

            refreshDirtyState();
        }
    });

    form.querySelector('[data-add-pattern]')?.addEventListener('click', () => {
        const tbody = form.querySelector('[data-pattern-rows]');

        if (
            !(tbody instanceof HTMLTableSectionElement)
            || !(patternRowTemplate instanceof HTMLTemplateElement)
        ) {
            return;
        }

        const fragment = patternRowTemplate.content.cloneNode(true);
        const addedRow = fragment.querySelector('[data-pattern-row]');
        tbody.insertBefore(fragment, tbody.querySelector('[data-empty-patterns]'));
        const emptyRow = form.querySelector('[data-empty-patterns]');

        if (emptyRow instanceof HTMLTableRowElement) {
            emptyRow.hidden = true;
        }

        normalizePatternNames();
        addedRow?.querySelector('input')?.focus();
        refreshDirtyState();
    });

    const staffingMode = form.querySelector('[data-staffing-mode]');
    const fixedSettings = form.querySelector('[data-fixed-staffing]');
    const patternSettings = form.querySelector('[data-pattern-staffing]');
    const refreshStaffingVisibility = () => {
        if (!(staffingMode instanceof HTMLSelectElement)) {
            return;
        }

        if (fixedSettings instanceof HTMLElement) {
            fixedSettings.hidden = staffingMode.value !== 'fixed_total';
        }

        if (patternSettings instanceof HTMLElement) {
            patternSettings.hidden = staffingMode.value !== 'pattern_combinations';
        }
    };

    staffingMode?.addEventListener('change', refreshStaffingVisibility);
    form.addEventListener('input', refreshDirtyState);
    form.addEventListener('change', refreshDirtyState);
    form.addEventListener('submit', () => {
        normalizePatternNames();
        isSubmitting = true;

        if (saveButton instanceof HTMLButtonElement) {
            saveButton.disabled = true;
            saveButton.textContent = '保存中…';
        }

        if (saveStatus instanceof HTMLElement) {
            saveStatus.textContent = '保存中です';
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (!isSubmitting && formState() !== initialState) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    window.addEventListener('pageshow', () => {
        isSubmitting = false;

        if (saveButton instanceof HTMLButtonElement) {
            saveButton.textContent = '保存';
        }

        refreshDirtyState();
    });

    normalizePatternNames();
    refreshStaffingVisibility();
    initialState = formState();
    refreshDirtyState();
})();
