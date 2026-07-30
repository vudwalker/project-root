(() => {
    'use strict';

    function createShiftPublicationController({
        editor,
        requestJson,
        RequestError,
        ConflictError,
        getDraftVersion,
        hasUnsaved,
        hasSchedule,
        isStopped,
        onConflict,
        applyWarningResult,
    }) {
        const button = editor.querySelector('.admin-publish-button');
        const note = editor.querySelector('.admin-publish-note');
        const publishUrl = editor.dataset.publishShiftsUrl;
        const targetMonth = editor.dataset.targetMonth;
        let canPublish = editor.dataset.canPublish === 'true';
        let draftVersion = parseNonNegativeInteger(getDraftVersion());
        let publishedVersion = parsePositiveInteger(
            editor.dataset.publishedVersion,
        );
        let publishedDraftVersion = parseNonNegativeInteger(
            editor.dataset.publishedDraftVersion,
        );
        let publishedAt = editor.dataset.publishedAt || '';
        let autosaveState = 'idle';
        let publishing = false;
        let failureMessage = '';

        button?.addEventListener('click', publish);
        refresh();

        function updateEligibility(eligible) {
            canPublish = eligible === true;
            editor.dataset.canPublish = canPublish ? 'true' : 'false';

            if (!canPublish) {
                failureMessage = '';
            }

            refresh();
        }

        function updateDraftVersion(version) {
            const parsed = parseNonNegativeInteger(version);

            if (parsed !== null) {
                draftVersion = parsed;
                refresh();
            }
        }

        function updateAutosaveState(state) {
            autosaveState = state;
            refresh();
        }

        function refresh() {
            if (!button) {
                return;
            }

            const state = publicationState();
            const unsaved = hasUnsaved();
            const unavailable = !publishUrl || !targetMonth || !hasSchedule();

            editor.dataset.publicationState = state;
            button.disabled = publishing
                || unavailable
                || isStopped()
                || unsaved
                || !canPublish
                || state === 'published';
            button.textContent = publishing
                ? '配布中…'
                : buttonLabel(state);
            button.title = buttonTitle(state, unavailable, unsaved);

            if (!note) {
                return;
            }

            note.textContent = noteText(state, unavailable, unsaved);
            note.classList.toggle(
                'is-warning',
                !canPublish
                    || Boolean(failureMessage)
                    || state === 'requires_republish',
            );
        }

        async function publish() {
            if (!button || button.disabled || publishing) {
                return;
            }

            publishing = true;
            failureMessage = '';
            refresh();

            try {
                const payload = await requestJson(publishUrl, {
                    method: 'POST',
                    body: {
                        target_month: targetMonth,
                        expected_draft_version: draftVersion,
                    },
                });

                publishedVersion = parsePositiveInteger(
                    payload.published_version,
                );
                publishedDraftVersion = parseNonNegativeInteger(
                    payload.published_draft_version,
                );
                editor.dataset.publishedVersion = String(publishedVersion);
                editor.dataset.publishedDraftVersion = String(
                    publishedDraftVersion,
                );
                publishedAt = payload.published_at || '';
                editor.dataset.publishedAt = publishedAt;
                editor.dataset.publishedByUserId = String(
                    payload.published_by_user_id || '',
                );
                applyWarningResult(payload.warning_result);
            } catch (error) {
                if (error instanceof ConflictError) {
                    onConflict(error);

                    return;
                }

                failureMessage = error instanceof RequestError
                    ? error.message
                    : '配布できませんでした。時間をおいて再試行してください。';

                if (error instanceof RequestError) {
                    applyWarningResult(error.payload?.warning_result);
                }
            } finally {
                publishing = false;
                refresh();
            }
        }

        function publicationState() {
            if (publishedDraftVersion === null) {
                return 'unpublished';
            }

            return draftVersion > publishedDraftVersion
                ? 'requires_republish'
                : 'published';
        }

        function buttonLabel(state) {
            if (state === 'published') {
                return '配布済み';
            }

            return state === 'requires_republish' ? '再配布' : 'シフト配布';
        }

        function buttonTitle(state, unavailable, unsaved) {
            if (unavailable) {
                return '配布する下書きがありません';
            }

            if (isStopped()) {
                return '競合を解消してから配布してください';
            }

            if (unsaved || ['pending', 'saving', 'failed'].includes(autosaveState)) {
                return '自動保存の完了後に配布できます';
            }

            if (!canPublish) {
                return '警告を解消するまで配布できません';
            }

            if (state === 'published') {
                return '最新の下書きは配布済みです';
            }

            return state === 'requires_republish'
                ? '最新の下書きを再配布します'
                : '下書きを配布します';
        }

        function noteText(state, unavailable, unsaved) {
            if (failureMessage) {
                return `配布失敗：${failureMessage}`;
            }

            if (publishing) {
                return '配布中';
            }

            if (unavailable) {
                return '下書き未作成';
            }

            if (isStopped()) {
                return '競合を解消してから配布してください';
            }

            if (autosaveState === 'failed') {
                return '保存失敗を解消してから配布してください';
            }

            if (unsaved || ['pending', 'saving'].includes(autosaveState)) {
                return '保存完了後に配布できます';
            }

            if (!canPublish) {
                return '修正が必要で配布不可';
            }

            if (state === 'published') {
                return withPublishedAt('配布済み');
            }

            return state === 'requires_republish'
                ? withPublishedAt('再配布が必要')
                : '未配布';
        }

        function withPublishedAt(label) {
            if (!publishedAt) {
                return label;
            }

            const date = new Date(publishedAt);

            if (Number.isNaN(date.getTime())) {
                return label;
            }

            return `${label}（最終配布 ${date.toLocaleString('ja-JP', {
                month: 'numeric',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            })}）`;
        }

        return Object.freeze({
            refresh,
            updateAutosaveState,
            updateDraftVersion,
            updateEligibility,
        });
    }

    function parseNonNegativeInteger(value) {
        if (value === '' || value === null || value === undefined) {
            return null;
        }

        const number = Number(value);

        return Number.isSafeInteger(number) && number >= 0 ? number : null;
    }

    function parsePositiveInteger(value) {
        const number = Number(value);

        return Number.isSafeInteger(number) && number >= 1 ? number : null;
    }

    const modules = window.AdminShiftEditorModules
        || (window.AdminShiftEditorModules = {});

    modules.publication = Object.freeze({
        createShiftPublicationController,
    });
})();
