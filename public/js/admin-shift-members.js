(() => {
    'use strict';

    const root = document.querySelector('[data-monthly-members]');

    if (!root) {
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const targetMonth = root.dataset.targetMonth;
    const addUrl = root.dataset.addUrl;
    const removeUrlTemplate = root.dataset.removeUrlTemplate;
    const reorderUrl = root.dataset.reorderUrl;
    const list = root.querySelector('[data-members-list]');
    const candidate = root.querySelector('[data-member-candidate]');
    const addButton = root.querySelector('[data-member-add]');
    const status = root.querySelector('[data-members-status]');
    const conflict = root.querySelector('[data-members-conflict]');
    const reloadButton = root.querySelector('[data-members-reload]');
    let version = Number(root.dataset.monthlyMembersVersion);
    let busy = false;

    if (
        !csrfToken
        || !targetMonth
        || !addUrl
        || !removeUrlTemplate
        || !reorderUrl
        || !list
        || !candidate
        || !Number.isInteger(version)
    ) {
        return;
    }

    addButton?.addEventListener('click', async () => {
        const userId = Number(candidate.value);

        if (!userId || busy) {
            return;
        }

        await mutate(addUrl, 'POST', {user_id: userId});
    });

    list.addEventListener('click', async (event) => {
        const button = event.target.closest('button');
        const item = event.target.closest('[data-member-id]');

        if (!button || !item || busy) {
            return;
        }

        if (button.hasAttribute('data-member-remove')) {
            await mutate(
                removeUrlTemplate.replace('__USER_ID__', item.dataset.memberId),
                'DELETE',
            );

            return;
        }

        const direction = button.dataset.memberMove;
        const sibling = direction === 'up'
            ? item.previousElementSibling
            : item.nextElementSibling;

        if (!sibling || !sibling.hasAttribute('data-member-id')) {
            return;
        }

        if (direction === 'up') {
            item.parentElement.insertBefore(item, sibling);
        } else {
            item.parentElement.insertBefore(sibling, item);
        }

        await mutate(reorderUrl, 'PATCH', {
            user_ids: Array.from(list.querySelectorAll('[data-member-id]'))
                .map((member) => Number(member.dataset.memberId)),
        });
    });

    reloadButton?.addEventListener('click', () => window.location.reload());

    async function mutate(url, method, payload = {}) {
        busy = true;
        setStatus('保存中', 'saving');
        hideConflict();

        try {
            const response = await fetch(url, {
                method,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    target_month: targetMonth,
                    expected_monthly_members_version: version,
                    ...payload,
                }),
            });
            const result = await response.json();

            if (response.status === 409) {
                showConflict();
                setStatus('競合', 'conflict');

                return;
            }

            if (!response.ok) {
                throw new Error(result.message || '保存に失敗しました。');
            }

            version = Number(result.monthly_members_version);
            root.dataset.monthlyMembersVersion = String(version);
            setStatus('保存済み', 'saved');
            window.location.reload();
        } catch (error) {
            setStatus(error.message || '保存に失敗しました。', 'error');
        } finally {
            busy = false;
        }
    }

    function setStatus(message, state) {
        if (!status) {
            return;
        }

        status.textContent = message;
        status.dataset.state = state;
    }

    function showConflict() {
        if (conflict) {
            conflict.hidden = false;
        }
    }

    function hideConflict() {
        if (conflict) {
            conflict.hidden = true;
        }
    }
})();
