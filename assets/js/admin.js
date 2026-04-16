(function ($) {
    'use strict';

    $(document).ready(function () {
        var $wrapper = $('#ot-model-field-wrapper');
        var $input = $('#ot-model-input');
        var $btn = $('#ot-fetch-models-btn');
        var $msg = $('#ot-model-message');

        $btn.on('click', function () {
            var provider = $('select[name="provider"]').val();
            var apiKey = $('input[name="api_key"]').val().trim();
            var baseUrl = $('input[name="base_url"]').val().trim();

            if (!apiKey) {
                $msg.text('请先填写 API Key。').show().css('color', '#d63638');
                return;
            }

            $btn.prop('disabled', true).text(opentranslation_ajax.strings.fetching);
            $msg.hide();

            $.post(opentranslation_ajax.ajax_url, {
                action: 'opentranslation_fetch_models',
                nonce: opentranslation_ajax.nonce,
                provider: provider,
                api_key: apiKey,
                base_url: baseUrl,
            }, function (response) {
                $btn.prop('disabled', false).text(opentranslation_ajax.strings.fetch_models);

                if (response.success && Array.isArray(response.data) && response.data.length > 0) {
                    var currentVal = $input.val();
                    var $select = $('<select>', {
                        id: 'ot-model-input',
                        name: 'model',
                        class: 'regular-text',
                    });

                    response.data.forEach(function (model) {
                        $('<option>', {
                            value: model,
                            text: model,
                            selected: model === currentVal,
                        }).appendTo($select);
                    });

                    $input.replaceWith($select);
                    $input = $select;
                    $msg.text('').hide();
                } else {
                    $msg.text(opentranslation_ajax.strings.no_models).show().css('color', '#d63638');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(opentranslation_ajax.strings.fetch_models);
                $msg.text(opentranslation_ajax.strings.error).show().css('color', '#d63638');
            });
        });

        $('.ot-test-model-btn').on('click', function () {
            var $btn = $(this);
            var $result = $btn.siblings('.ot-test-model-result');
            var index = $btn.data('index');

            $btn.prop('disabled', true).text(opentranslation_ajax.strings.testing);
            $result.text('');

            $.post(opentranslation_ajax.ajax_url, {
                action: 'opentranslation_test_model',
                nonce: opentranslation_ajax.test_nonce,
                model_index: index,
            }, function (response) {
                $btn.prop('disabled', false).text(opentranslation_ajax.strings.test_model);
                if (response.success) {
                    $result.text(opentranslation_ajax.strings.test_success).css('color', '#00a32a');
                } else {
                    var msg = response.data || opentranslation_ajax.strings.test_failed;
                    $result.text(msg).css('color', '#d63638');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(opentranslation_ajax.strings.test_model);
                $result.text(opentranslation_ajax.strings.test_failed).css('color', '#d63638');
            });
        });
    });
})(jQuery);
