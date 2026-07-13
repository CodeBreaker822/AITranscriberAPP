export const clampProgressPercent = (value) => Math.max(0, Math.min(100, Number(value || 0)));

export const createPhaseProgress = (definitions) => Object.fromEntries(
    definitions.map((phase) => [phase.key, 0]),
);

export const phaseProgressAverage = (phases, definitions) => {
    const keys = definitions.map((phase) => phase.key);

    if (!keys.length) {
        return 0;
    }

    const total = keys.reduce((sum, key) => sum + clampProgressPercent(phases?.[key]), 0);

    return Math.round(total / keys.length);
};

export const phaseProgressSummary = (phases, definitions) => definitions
    .map((phase) => `${phase.label} ${Math.round(clampProgressPercent(phases?.[phase.key]))}%`)
    .join(' | ');
