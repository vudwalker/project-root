(() => {
    'use strict';

    const form = document.querySelector('[data-admin-staff-form]');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const saveButton = form.querySelector('[data-admin-staff-save]');
    const saveStatus = form.querySelector('[data-admin-staff-save-status]');
    let initialState = '';
    let isSubmitting = false;

    const formState = () => new URLSearchParams(new FormData(form)).toString();

    const refreshState = () => {
        const isDirty = formState() !== initialState;

        if (saveButton instanceof HTMLButtonElement) {
            saveButton.disabled = !isDirty || isSubmitting;
        }

        if (saveStatus instanceof HTMLElement) {
            saveStatus.textContent = isDirty
                ? '未保存の変更があります'
                : '変更はありません';
        }
    };

    form.addEventListener('input', refreshState);
    form.addEventListener('change', refreshState);
    form.addEventListener('submit', () => {
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

        refreshState();
    });

    initialState = formState();
    refreshState();
})();
