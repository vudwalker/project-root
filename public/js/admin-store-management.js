(() => {
    'use strict';

    const form = document.querySelector('[data-staffing-settings]');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const mode = form.querySelector('[data-staffing-mode]');
    const fixedSettings = form.querySelector('[data-fixed-staffing]');
    const patternSettings = form.querySelector('[data-pattern-staffing]');

    if (!(mode instanceof HTMLSelectElement)) {
        return;
    }

    const refreshVisibility = () => {
        if (fixedSettings instanceof HTMLElement) {
            fixedSettings.hidden = mode.value !== 'fixed_total';
        }

        if (patternSettings instanceof HTMLElement) {
            patternSettings.hidden = mode.value !== 'pattern_combinations';
        }
    };

    mode.addEventListener('change', refreshVisibility);
    refreshVisibility();
})();
