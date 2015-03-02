/*jshint browser:true, devel:true*/
/*global define*/
define(['jquery', 'underscore'], function ($, _) {
    'use strict';

    return function () {
        $(function () {
            function prepareTinymce(textareas) {
                if (textareas.length > 0) {
                    $(textareas).each(function (i, el){
                        var tinymceInstance = $(el).tinymce();
                        if (tinymceInstance) {
                            if ($(el).prop('disabled')) {
                                tinymceInstance.editorManager.activeEditor.hide()
                                tinymceInstance.editorManager.activeEditor.setContent('')
                                $(el).prop('value', '');
                                $(el).empty();
                            } else {
                                tinymceInstance.editorManager.activeEditor.show();
                            }
                        }
                    });
                }
            }
            prepareTinymce($.find('textarea'));
            var value, valueEls, textareas,
                checkboxEls = $('.parent-scope-checkbox input');
            checkboxEls.on('change', function () {
                value = $(this).is(':checked');
                valueEls = $(this).parents('.controls').find(':input').not(checkboxEls);
                valueEls.each(function (i, el) {
                    $(el)
                        .prop('disabled', value)
                        .data('disabled', value)
                        .trigger(value ? 'disable' : 'enable');

                    if (!_.isUndefined($.uniform) && _.contains($.uniform.elements, el)) {
                        $(el).uniform('update');
                    }
                });

                prepareTinymce($(this).parents('.controls').find('textarea'));
            });
        });
    };
});
