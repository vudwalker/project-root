(function () {
    'use strict';

    /*
     * このファイル内の変数や関数が他のJavaScriptとぶつからないように、
     * 全体を即時関数（IIFE）で囲んでいます。
     */

    // HTMLの読み込みが完了してから、各画面に必要な処理を準備します。
    document.addEventListener('DOMContentLoaded', function () {
        // 個人画面にだけ存在する要素を使う処理です。
        setupPersonalScale();
        // 個人画面・店舗別画面の両方で使う店舗メニューの処理です。
        setupStoreMenus();
        // 店舗別画面にだけ存在する横スクロールの処理です。
        setupStoreScroll();
        // 対象年に応じて、選択可能な月だけを月選択へ表示します。
        setupTargetMonthSelectors();
    });

    /**
     * 個人カレンダー全体を同じ倍率で縮小します。
     * セル単位や文字単位は縮小せず、縦横比を維持します。
     */
    function setupPersonalScale() {
        /*
         * data属性は、見た目用のclassとJavaScript用の目印を分けるために使います。
         * CSSクラス名を変更しても、data属性を残せばこの処理は動作します。
         */
        var wrapper = document.querySelector('[data-personal-scale-wrapper]');
        var content = document.querySelector('[data-personal-scale-content]');

        // 個人画面以外には対象要素がないため、何もせず終了します。
        if (!wrapper || !content) {
            return;
        }

        // resizeイベントが短時間に連続しても、計算を何度も行わないための印です。
        var scheduled = false;

        function resize() {
            scheduled = false;

            // 縮小前の本来の幅と高さを取得します。
            var originalWidth = content.offsetWidth;
            var originalHeight = content.offsetHeight;
            // ページ内で実際に使用できる横幅です。
            var availableWidth = wrapper.clientWidth;

            // 最大倍率は1（PCでは拡大しない）
            var scale = Math.min(1, availableWidth / originalWidth);

            // 縮小時は丸め誤差で右端の罫線が切れやすいため、
            // 1px分の余裕を持たせて倍率を決めます。
            if (scale < 1) {
                scale = Math.max(0, (availableWidth - 1) / originalWidth);
            }

            content.style.transform = 'scale(' + scale + ')';

            /*
             * transformで見た目を縮小しても、HTML上の占有サイズは元のままです。
             * 外側の高さを縮小後に合わせ、下に大きな空白が残らないようにします。
             * 切り捨てると下端の罫線が切れるため、Math.ceilで切り上げます。
             */
            wrapper.style.height = Math.ceil(originalHeight * scale) + 'px';
        }

        function scheduleResize() {
            // すでに次の描画タイミングで実行予定なら、重ねて予約しません。
            if (scheduled) {
                return;
            }

            scheduled = true;
            // ブラウザが画面を描画する直前にサイズを計算します。
            window.requestAnimationFrame(resize);
        }

        // ウィンドウ幅や端末の向きが変わったときに倍率を計算し直します。
        window.addEventListener('resize', scheduleResize);
        window.addEventListener('orientationchange', scheduleResize);
        // 初回表示にも同じ計算を行います。
        scheduleResize();
    }

    /**
     * 店舗切り替えメニューをキーボード操作にも対応させます。
     */
    function setupStoreMenus() {
        // 画面内にあるすべての店舗メニューを取得します。
        var menus = document.querySelectorAll('[data-store-menu]');

        menus.forEach(function (menu) {
            var trigger = menu.querySelector('[data-store-menu-trigger]');
            var list = menu.querySelector('[data-store-menu-list]');

            // ボタンか一覧のどちらかがなければ、そのメニューだけ処理を終了します。
            if (!trigger || !list) {
                return;
            }

            // 閉じる処理を共通化し、外側クリックとEscキーの両方から使います。
            function close() {
                trigger.setAttribute('aria-expanded', 'false');
                list.hidden = true;
            }

            trigger.addEventListener('click', function () {
                // 現在の状態を確認し、開いていれば閉じ、閉じていれば開きます。
                var isOpen = trigger.getAttribute('aria-expanded') === 'true';
                trigger.setAttribute('aria-expanded', String(!isOpen));
                list.hidden = isOpen;
            });

            document.addEventListener('click', function (event) {
                // メニューの外側をクリックした場合だけ閉じます。
                if (!menu.contains(event.target)) {
                    close();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    close();
                    // キーボード操作を続けやすいよう、開閉ボタンへフォーカスを戻します。
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

        // 店舗別画面以外には対象要素がないため、何もせず終了します。
        if (!scrollContainer) {
            return;
        }

        // ユーザーが手動で動かした後は、自動スクロールで位置を戻さないための印です。
        var userScrolled = false;
        // 初回の自動スクロールを、ユーザー操作として誤判定しないための印です。
        var initialized = false;

        scrollContainer.addEventListener('scroll', function () {
            if (initialized) {
                userScrolled = true;
            }
        }, { passive: true });

        function moveToToday() {
            // 手動操作後はユーザーが選んだ位置を優先します。
            if (userScrolled) {
                return;
            }

            // data-is-today="true"が付いた最初のセルを当日列の基準にします。
            var todayColumn = scrollContainer.querySelector('[data-is-today="true"]');

            // 表示月に当日が含まれない場合は、自動スクロールしません。
            if (!todayColumn) {
                return;
            }

            /*
             * 当日セルの中心位置から表示領域の半分を引き、
             * 当日列がおおよそ画面中央に来るスクロール位置を求めます。
             */
            var target = todayColumn.offsetLeft
                - (scrollContainer.clientWidth / 2)
                + (todayColumn.offsetWidth / 2);
            // 表の右端より先へスクロールしないための最大値です。
            var maxScroll = scrollContainer.scrollWidth - scrollContainer.clientWidth;

            // 0未満・最大値超過を防いでからスクロール位置へ設定します。
            scrollContainer.scrollLeft = Math.max(0, Math.min(target, maxScroll));
            initialized = true;
        }

        // 表のレイアウトが確定してから、初回の当日列へ移動します。
        window.requestAnimationFrame(moveToToday);
        window.addEventListener('resize', function () {
            // 手動スクロール前だけ、画面幅変更後も当日列を中央付近へ合わせ直します。
            if (!userScrolled) {
                window.requestAnimationFrame(moveToToday);
            }
        });
    }

    /**
     * サーバーが検証済みの年月範囲だけを、年月選択へ表示します。
     */
    function setupTargetMonthSelectors() {
        var navigations = document.querySelectorAll('[data-staff-month-navigation]');

        navigations.forEach(function (navigation) {
            var form = navigation.querySelector('[data-staff-month-form]');

            if (!form) {
                return;
            }

            var yearSelect = form.querySelector('[data-month-year]');
            var monthSelect = form.querySelector('[data-month-number]');

            if (!yearSelect || !monthSelect) {
                return;
            }

            yearSelect.addEventListener('change', function () {
                replaceSelectableMonths(yearSelect, monthSelect);
            });
        });
    }

    function replaceSelectableMonths(yearSelect, monthSelect) {
        var selectedYearOption = yearSelect.options[yearSelect.selectedIndex];
        var months = selectedYearOption.dataset.months
            .split(',')
            .filter(function (month) {
                return month !== '';
            });
        var currentMonth = monthSelect.value;
        var canKeepCurrentMonth = months.indexOf(currentMonth) !== -1;

        monthSelect.replaceChildren();

        months.forEach(function (month, index) {
            var option = document.createElement('option');

            option.value = month;
            option.textContent = month + '月';
            option.selected = canKeepCurrentMonth
                ? month === currentMonth
                : index === 0;
            monthSelect.appendChild(option);
        });
    }
}());
