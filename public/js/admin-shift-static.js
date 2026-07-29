(() => {
    'use strict';

    const modeButtons = Array.from(document.querySelectorAll('[data-static-shift-mode]'));
    const modeStatus = document.querySelector('[data-static-mode-status]');

    setupMonthNavigationGuard();

    /**
     * 静的UI確認用に、入力モードの選択状態だけを切り替えます。
     * セル変更、通信、自動保存、配布は実行しません。
     */
    modeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const willSelect = button.getAttribute('aria-pressed') !== 'true';

            modeButtons.forEach((candidate) => {
                candidate.classList.remove('is-selected');
                candidate.setAttribute('aria-pressed', 'false');
            });

            if (willSelect) {
                button.classList.add('is-selected');
                button.setAttribute('aria-pressed', 'true');
            }

            if (modeStatus) {
                const mode = button.dataset.staticShiftMode;
                const label = mode === 'delete' ? '削除' : `${mode}入力`;
                modeStatus.textContent = willSelect ? `${label}モード（静的）` : '入力未選択';
            }
        });
    });

    document.addEventListener('click', (event) => {
        document.querySelectorAll('details[open]').forEach((details) => {
            if (!details.contains(event.target)) {
                details.removeAttribute('open');
            }
        });
    });

    /**
     * 将来の自動保存処理と月移動を安全に接続するための状態契約です。
     *
     * 自動保存側は `admin-shift:autosave-state` を次のdetailで通知します。
     * { state: 'pending'|'saving'|'saved'|'failed', month: 'YYYY-MM' }
     *
     * 保存待ちで月移動が要求された場合、この処理は
     * `admin-shift:autosave-flush-request` を通知します。
     * 現在の静的UIは保存通信へ接続していないため、通常時は通常リンクとして移動します。
     */
    function setupMonthNavigationGuard() {
        const navigation = document.querySelector('[data-admin-month-navigation]');

        if (!navigation) {
            return;
        }

        const currentMonth = navigation.dataset.targetMonth;
        const form = navigation.querySelector('[data-admin-month-form]');
        const links = Array.from(document.querySelectorAll(
            '[data-admin-month-link], [data-admin-shift-navigation-link]',
        ));
        const error = navigation.querySelector('[data-admin-month-navigation-error]');
        const formControls = form
            ? Array.from(form.querySelectorAll('select, button'))
            : [];
        let saveState = 'idle';
        let pendingDestination = null;

        setupYearMonthSelector(form);

        links.forEach((link) => {
            link.addEventListener('click', (event) => {
                requestNavigation(event, link.href);
            });
        });

        if (form) {
            form.addEventListener('submit', (event) => {
                requestNavigation(event, formDestination(form));
            });
        }

        document.addEventListener('admin-shift:autosave-state', (event) => {
            const detail = event.detail || {};

            // 前の対象月から返った非同期レスポンスは、現在のDOMへ反映しません。
            if (detail.month && detail.month !== currentMonth) {
                return;
            }

            if (!['pending', 'saving', 'saved', 'failed', 'idle'].includes(detail.state)) {
                return;
            }

            saveState = detail.state;

            if (saveState === 'pending') {
                hideError();

                return;
            }

            if (saveState === 'saving') {
                setNavigationDisabled(true);

                return;
            }

            if (saveState === 'failed') {
                pendingDestination = null;
                setNavigationDisabled(false);
                showError('保存に失敗したため、対象月を変更していません。入力内容を確認してください。');

                return;
            }

            setNavigationDisabled(false);
            hideError();

            if (saveState === 'saved' && pendingDestination) {
                const destination = pendingDestination;
                pendingDestination = null;
                window.location.assign(destination);
            }
        });

        function requestNavigation(event, destination) {
            if (saveState === 'idle' || saveState === 'saved') {
                return;
            }

            event.preventDefault();

            if (pendingDestination) {
                return;
            }

            if (saveState === 'failed') {
                showError('保存に失敗しているため、対象月を変更できません。');

                return;
            }

            pendingDestination = destination;
            setNavigationDisabled(true);

            if (saveState === 'pending') {
                document.dispatchEvent(new CustomEvent('admin-shift:autosave-flush-request', {
                    detail: {
                        month: currentMonth,
                    },
                }));
            }
        }

        function setNavigationDisabled(disabled) {
            links.forEach((link) => {
                link.setAttribute('aria-disabled', String(disabled));
            });
            formControls.forEach((control) => {
                control.disabled = disabled;
            });
        }

        function showError(message) {
            if (!error) {
                return;
            }

            error.textContent = message;
            error.hidden = false;
        }

        function hideError() {
            if (!error) {
                return;
            }

            error.textContent = '';
            error.hidden = true;
        }
    }

    /**
     * 対象年を変更したとき、サーバーが許可した月だけを選択肢へ残します。
     */
    function setupYearMonthSelector(form) {
        if (!form) {
            return;
        }

        const yearSelect = form.querySelector('[data-month-year]');
        const monthSelect = form.querySelector('[data-month-number]');

        if (!yearSelect || !monthSelect) {
            return;
        }

        yearSelect.addEventListener('change', () => {
            const selectedYearOption = yearSelect.options[yearSelect.selectedIndex];
            const months = selectedYearOption.dataset.months
                .split(',')
                .filter((month) => month !== '');
            const currentMonth = monthSelect.value;
            const canKeepCurrentMonth = months.includes(currentMonth);

            monthSelect.replaceChildren();

            months.forEach((month, index) => {
                const option = document.createElement('option');

                option.value = month;
                option.textContent = `${month}月`;
                option.selected = canKeepCurrentMonth
                    ? month === currentMonth
                    : index === 0;
                monthSelect.appendChild(option);
            });
        });
    }

    function formDestination(form) {
        const destination = new URL(form.action, window.location.href);
        const formData = new FormData(form);

        formData.forEach((value, name) => {
            destination.searchParams.set(name, String(value));
        });

        return destination.toString();
    }
})();
