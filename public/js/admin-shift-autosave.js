(() => {
    'use strict';

    function createAutosaveQueue({
        debounceMs,
        onStateChange,
        onConflict,
        markElementState,
        ConflictError,
        RequestError,
    }) {
        const items = new Map();
        let hasCompletedSave = false;
        let networkBusy = false;
        let stopped = false;
        let nextOperationId = 1;
        let lastAppliedOperationId = 0;

        function enqueue(key, operation) {
            if (stopped) {
                return;
            }

            const item = items.get(key) || {
                pending: null,
                saving: false,
                failed: false,
                timer: null,
                ready: false,
            };

            if (item.timer) {
                window.clearTimeout(item.timer);
            }

            item.pending = {
                ...operation,
                operationId: nextOperationId++,
            };
            item.failed = false;
            item.ready = false;
            item.timer = window.setTimeout(() => {
                item.timer = null;
                item.ready = true;
                drainNext();
            }, debounceMs);
            items.set(key, item);
            markElementState(operation.element, 'pending');
            publishState();
        }

        async function drainNext() {
            if (networkBusy || stopped) {
                return;
            }

            const next = Array.from(items.entries())
                .filter(([, item]) => item.ready && !item.saving && item.pending)
                .sort(
                    ([, left], [, right]) => left.pending.operationId
                        - right.pending.operationId,
                )
                .at(0);

            if (!next) {
                return;
            }

            const [key, item] = next;
            const operation = item.pending;

            item.pending = null;
            item.ready = false;
            item.saving = true;
            item.failed = false;
            networkBusy = true;
            markElementState(operation.element, 'saving');
            publishState();

            try {
                const payload = await operation.execute();

                if (stopped || operation.operationId <= lastAppliedOperationId) {
                    item.saving = false;
                    networkBusy = false;
                    publishState();

                    return;
                }

                lastAppliedOperationId = operation.operationId;
                operation.onSuccess(payload);
                item.saving = false;
                networkBusy = false;
                hasCompletedSave = true;

                if (item.pending) {
                    publishState();
                    drainNext();

                    return;
                }

                items.delete(key);
                publishState();
                drainNext();
            } catch (error) {
                item.saving = false;
                networkBusy = false;

                if (error instanceof ConflictError) {
                    stopForConflict(error);

                    return;
                }

                if (item.pending) {
                    publishState();
                    drainNext();

                    return;
                }

                item.failed = true;
                item.failedMessage = error instanceof RequestError
                    ? error.message
                    : '保存できませんでした。';
                operation.onFailure(item.failedMessage);
                publishState();
                drainNext();
            }
        }

        function stopForConflict(error) {
            stopped = true;
            items.forEach((item) => {
                if (item.timer) {
                    window.clearTimeout(item.timer);
                    item.timer = null;
                }

                item.ready = false;
            });
            onConflict(error.message, error.payload);
            publishState();
        }

        function cancelPending(key) {
            const item = items.get(key);

            if (!item || item.saving || item.failed) {
                return false;
            }

            if (item.timer) {
                window.clearTimeout(item.timer);
            }

            items.delete(key);
            publishState();
            drainNext();

            return true;
        }

        function flush() {
            items.forEach((item) => {
                if (item.timer) {
                    window.clearTimeout(item.timer);
                    item.timer = null;
                }

                if (item.pending && !item.saving) {
                    item.ready = true;
                }
            });

            publishState();
            drainNext();
        }

        function state() {
            if (stopped) {
                return 'conflict';
            }

            if (Array.from(items.values()).some((item) => item.failed)) {
                return 'failed';
            }

            if (Array.from(items.values()).some((item) => item.saving)) {
                return 'saving';
            }

            if (Array.from(items.values()).some((item) => item.pending || item.timer)) {
                return 'pending';
            }

            return hasCompletedSave ? 'saved' : 'idle';
        }

        function publishState() {
            const currentState = state();
            const failedMessage = Array.from(items.values())
                .find((item) => item.failed)?.failedMessage;

            onStateChange(currentState, failedMessage);
        }

        return {
            enqueue,
            cancelPending,
            flush,
            hasUnsaved: () => stopped || items.size > 0,
            isStopped: () => stopped,
            stopForConflict,
        };
    }

    const modules = window.AdminShiftEditorModules
        || (window.AdminShiftEditorModules = {});

    modules.autosave = Object.freeze({
        createAutosaveQueue,
    });
})();
