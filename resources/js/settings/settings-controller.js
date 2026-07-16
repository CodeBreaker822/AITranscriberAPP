export const initSettingsPage = () => {
    const $speechProviderSelect = $('[data-speech-provider-select]');
    const $speechProviderPanels = $('[data-speech-provider-panel]');
    const $serverSettingsForm = $('[data-settings-form]');
    const $serverProviderSelect = $('[data-server-provider-select]');
    const $serverModelSelect = $('[data-server-model-select]');
    const $resourceMode = $('[data-resource-mode]');
    const $resourceManualInputs = $('[data-resource-manual]');
    const $resourceGpuManualInputs = $('[data-resource-gpu-manual]');
    const syncSpeechProviderPanels = () => {
        const selectedProvider = String($speechProviderSelect.val() || 'elevenlabs');

        $speechProviderPanels.each(function () {
            const $panel = $(this);
            const isSelected = String($panel.data('speech-provider-panel') || '') === selectedProvider;

            $panel.toggleClass('hidden', !isSelected);
            $panel.find('input, select, textarea').prop('disabled', !isSelected);
        });
    };

    $speechProviderSelect.on('change', syncSpeechProviderPanels);
    syncSpeechProviderPanels();

    const syncServerModels = () => {
        if (!$serverSettingsForm.length || !$serverProviderSelect.length || !$serverModelSelect.length) {
            return;
        }

        let providers = {};

        try {
            providers = JSON.parse(String($serverSettingsForm.attr('data-provider-models') || '{}'));
        } catch (error) {
            providers = {};
        }

        const selectedProvider = String($serverProviderSelect.val() || '');
        const selectedModel = String($serverModelSelect.attr('data-selected-model') || $serverModelSelect.val() || '');
        const models = providers[selectedProvider]?.models || [];

        $serverModelSelect.empty();

        models.forEach((model) => {
            $('<option>')
                .val(String(model.id || ''))
                .text(String(model.label || model.id || ''))
                .prop('selected', String(model.id || '') === selectedModel)
                .appendTo($serverModelSelect);
        });

        if (!$serverModelSelect.val()) {
            $serverModelSelect.find('option').first().prop('selected', true);
        }
    };

    $serverProviderSelect.on('change', function () {
        $serverModelSelect.attr('data-selected-model', '');
        syncServerModels();
    });
    syncServerModels();

    const syncResourceControls = () => {
        const manual = String($resourceMode.val() || 'auto') === 'manual';
        $resourceManualInputs.prop('disabled', !manual).toggleClass('opacity-60', !manual);
        $resourceGpuManualInputs.each(function () {
            const $input = $(this);
            const enabled = manual && String($input.attr('data-gpu-available') || 'false') === 'true';

            $input.prop('disabled', !enabled).toggleClass('opacity-60', !enabled);
        });
    };

    $resourceMode.on('change', syncResourceControls);
    syncResourceControls();

    $('[data-settings-form]').on('submit', function () {
        const $saveButton = $(this).find('[data-settings-save]');

        if (typeof window.toggleLoading === 'function') {
            window.toggleLoading($saveButton, true);
            return;
        }

        $saveButton.prop('disabled', true);
    });


};
