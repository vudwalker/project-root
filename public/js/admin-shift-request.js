(() => {
    'use strict';

    class RequestError extends Error {
        constructor(message, payload = {}) {
            super(message);
            this.payload = payload;
        }
    }

    class ConflictError extends RequestError {
        constructor(message, payload) {
            super(message);
            this.payload = payload;
        }
    }

    function createRequestJsonClient({csrfToken}) {
        return async function requestJson(url, options) {
            let response;

            try {
                response = await fetch(url, {
                    method: options.method,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(options.body),
                });
            } catch (error) {
                throw new RequestError(
                    '通信できませんでした。入力内容を残したまま再試行できます。',
                );
            }

            const payload = await response.json().catch(() => ({}));

            if (response.ok) {
                return payload;
            }

            if (response.status === 401) {
                window.location.assign('/login');
                throw new RequestError(
                    '認証の有効期限が切れました。再度ログインしてください。',
                    payload,
                );
            }

            if (response.status === 422) {
                const validationMessage = Object.values(payload.errors || {})
                    .flat()
                    .find((message) => typeof message === 'string');

                throw new RequestError(
                    validationMessage
                        || payload.message
                        || '入力内容を確認してください。',
                    payload,
                );
            }

            if (response.status === 409) {
                throw new ConflictError(
                    payload.message
                        || '別の画面で更新されました。再読み込みしてください。',
                    payload,
                );
            }

            if (response.status === 403) {
                throw new RequestError(
                    'この店舗のシフトは変更できません。',
                    payload,
                );
            }

            if (response.status === 404) {
                throw new RequestError(
                    '対象のシフトが見つかりません。画面を再読み込みしてください。',
                    payload,
                );
            }

            throw new RequestError(
                '保存できませんでした。時間をおいて再試行してください。',
                payload,
            );
        };
    }

    const modules = window.AdminShiftEditorModules
        || (window.AdminShiftEditorModules = {});

    modules.request = Object.freeze({
        ConflictError,
        RequestError,
        createRequestJsonClient,
    });
})();
