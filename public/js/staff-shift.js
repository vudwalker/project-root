(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        setupPersonalScale();
        setupStoreMenus();
        setupStoreScroll();
    });

    /**
     * 個人カレンダー全体を同じ倍率で縮小します。
     * セル単位や文字単位は縮小せず、縦横比を維持します。
     */
    function setupPersonalScale() {
        var wrapper = document.querySelector('[data-personal-scale-wrapper]');
        var content = document.querySelector('[data-personal-scale-content]');

        if (!wrapper || !content) {
            return;
        }

        var scheduled = false;

        function resize() {
            scheduled = false;

            var originalWidth = content.offsetWidth;
            var originalHeight = content.offsetHeight;
            var availableWidth = wrapper.clientWidth;

            // 最大倍率は1（PCでは拡大しない）
            var scale = Math.min(1, availableWidth / originalWidth);

            // 縮小時は丸め誤差で右端の罫線が切れやすいため、
            // 1px分の余裕を持たせて倍率を決めます。
            if (scale < 1) {
                scale = Math.max(0, (availableWidth - 1) / originalWidth);
            }

            content.style.transform = 'scale(' + scale + ')';

            // 高さは切り捨てだと下端の罫線が切れるため、切り上げます。
            wrapper.style.height = Math.ceil(originalHeight * scale) + 'px';
        }

        function scheduleResize() {
            if (scheduled) {
                return;
            }

            scheduled = true;
            window.requestAnimationFrame(resize);
        }

        window.addEventListener('resize', scheduleResize);
        window.addEventListener('orientationchange', scheduleResize);
        scheduleResize();
    }

    /**
     * 店舗切り替えメニューをキーボード操作にも対応させます。
     */
    function setupStoreMenus() {
        var menus = document.querySelectorAll('[data-store-menu]');

        menus.forEach(function (menu) {
            var trigger = menu.querySelector('[data-store-menu-trigger]');
            var list = menu.querySelector('[data-store-menu-list]');

            if (!trigger || !list) {
                return;
            }

            function close() {
                trigger.setAttribute('aria-expanded', 'false');
                list.hidden = true;
            }

            trigger.addEventListener('click', function () {
                var isOpen = trigger.getAttribute('aria-expanded') === 'true';
                trigger.setAttribute('aria-expanded', String(!isOpen));
                list.hidden = isOpen;
            });

            document.addEventListener('click', function (event) {
                if (!menu.contains(event.target)) {
                    close();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    close();
                    trigger.focus();
                }
            });
        });
    }

    /**
     * 店舗別表はセル幅を維持し、初回だけ当日列を中央付近へ移動します。
     */
    function setupStoreScroll() {
        var scrollContainer = document.querySelector('[data-store-scroll]');

        if (!scrollContainer) {
            return;
        }

        var userScrolled = false;
        var initialized = false;

        scrollContainer.addEventListener('scroll', function () {
            if (initialized) {
                userScrolled = true;
            }
        }, { passive: true });

        function moveToToday() {
            if (userScrolled) {
                return;
            }

            var todayColumn = scrollContainer.querySelector('[data-is-today="true"]');

            if (!todayColumn) {
                return;
            }

            var target = todayColumn.offsetLeft
                - (scrollContainer.clientWidth / 2)
                + (todayColumn.offsetWidth / 2);
            var maxScroll = scrollContainer.scrollWidth - scrollContainer.clientWidth;

            scrollContainer.scrollLeft = Math.max(0, Math.min(target, maxScroll));
            initialized = true;
        }

        window.requestAnimationFrame(moveToToday);
        window.addEventListener('resize', function () {
            if (!userScrolled) {
                window.requestAnimationFrame(moveToToday);
            }
        });
    }
}());
