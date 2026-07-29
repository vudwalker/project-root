(() => {
    'use strict';

    const modeButtons = Array.from(document.querySelectorAll('[data-static-shift-mode]'));
    const modeStatus = document.querySelector('[data-static-mode-status]');

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
})();
