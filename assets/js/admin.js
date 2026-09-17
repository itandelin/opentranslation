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

        // 模型编辑：把行数据回填到表单
        $('.ot-edit-model-btn').on('click', function () {
            var $btn = $(this);

            $('#ot-form-index').val($btn.data('index'));
            $('select[name="provider"]').val($btn.data('provider'));
            $('input[name="base_url"]').val($btn.data('base-url'));
            $('input[name="priority"]').val($btn.data('priority'));
            $('input[name="temperature"]').val($btn.data('temperature'));
            $('input[name="max_tokens"]').val($btn.data('max-tokens'));
            $('#ot-api-key').val('');

            // model 字段可能已被「获取模型列表」替换为 select
            $('[name="model"]').val($btn.data('model'));

            $('#ot-form-title').text(opentranslation_ajax.strings.edit_model);
            // 隐藏并禁用「Add」：display:none 的提交按钮仍是表单默认按钮，
            // 不禁用的话在输入框按回车会以新增模式提交，产生重复模型
            $('#ot-submit-add').hide().prop('disabled', true);
            $('#ot-submit-update').show();
            $('#ot-cancel-edit').show();
            $('#ot-api-key-hint').show();

            $('html, body').animate({ scrollTop: $('#ot-model-form').offset().top - 40 }, 200);
        });

        $('#ot-cancel-edit').on('click', function () {
            $('#ot-model-form')[0].reset();
            $('#ot-form-index').val('');
            $('#ot-form-title').text(opentranslation_ajax.strings.add_model);
            $('#ot-submit-add').show().prop('disabled', false);
            $('#ot-submit-update').hide();
            $('#ot-cancel-edit').hide();
            $('#ot-api-key-hint').hide();
        });

        // 非 HTTPS 后台提示（S8）
        if (window.location.protocol !== 'https:') {
            $('#ot-api-key').after(
                $('<p class="description" style="color:#d63638;"></p>')
                    .text(opentranslation_ajax.strings.insecure_transport)
            );
        }
    });
})(jQuery);
