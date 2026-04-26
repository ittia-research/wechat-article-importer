jQuery(document).ready(function ($) {
    var importForm = $('#wai-importer-form');
    var submitButton = importForm.find('.wai-submit-button');
    var feedbackDiv = $('#wai-feedback');

    var taskData = {};
    var REQUEST_DELAY = 500;

    importForm.on('submit', function (event) {
        event.preventDefault();

        var wechatUrl = $('#wechat_url').val();
        var generateThumbnails = $('#wai_gen_thumbs').is(':checked');

        if (wechatUrl === '' || !wechatUrl.startsWith('https://mp.weixin.qq.com/')) {
            feedbackDiv.html('<div class="notice notice-error"><p>请输入一个有效的微信文章链接。</p></div>');
            return;
        }

        startImportProcess(wechatUrl, generateThumbnails);
    });


    function startImportProcess(wechatUrl, generateThumbnails) {
        submitButton.prop('disabled', true).val('正在解析文章...');
        feedbackDiv.html('<div class="notice notice-info"><p><strong>步骤 1/3:</strong> 正在解析文章结构，请稍候...</p></div>');
        $('.notice-success, .notice-error').remove();

        $.ajax({
            url: wai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wai_start_import',
                _ajax_nonce: wai_ajax.nonce,
                wechat_url: wechatUrl
            },
            success: function (response) {
                if (response.success) {
                    taskData = {
                        task_id: response.data.task_id,
                        image_urls: response.data.image_urls,
                        total_images: response.data.image_urls.length,
                        processed_count: 0,
                        generate_thumbnails: generateThumbnails
                    };
                    if (taskData.total_images > 0) {
                        processNextImage();
                    } else {
                        finishImportProcess();
                    }
                } else {
                    handleError(response.data.error || '解析文章失败。');
                }
            },
            error: function (jqXHR) {
                handleError('解析文章时发生服务器错误。', jqXHR);
            }
        });
    }


    function processNextImage() {
        if (taskData.processed_count >= taskData.total_images) {
            finishImportProcess();
            return;
        }

        var currentImageUrl = taskData.image_urls[taskData.processed_count];
        var progressText = (taskData.processed_count + 1) + ' / ' + taskData.total_images;

        var statusText = taskData.generate_thumbnails ? '下载并处理图片' : '正在快速下载图片';
        submitButton.val(statusText + ' (' + progressText + ')');
        feedbackDiv.html('<div class="notice notice-info"><p><strong>步骤 2/3:</strong> ' + statusText + ' ' + progressText + '，请保持页面开启...</p><div style="width: 100%; background: #eee; border-radius: 3px; overflow: hidden;"><div style="width: ' + ((taskData.processed_count + 1) / taskData.total_images * 100) + '%; background: #0073aa; height: 10px;"></div></div></div>');

        $.ajax({
            url: wai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wai_process_image',
                _ajax_nonce: wai_ajax.nonce,
                task_id: taskData.task_id,
                image_url: currentImageUrl,
                generate_thumbnails: taskData.generate_thumbnails
            },
            success: function (response) {
                if (response.success) {
                    taskData.processed_count++;
                    setTimeout(processNextImage, REQUEST_DELAY);
                } else {
                    console.warn('Skipping image due to error:', response.data.error);
                    taskData.processed_count++;

                    setTimeout(processNextImage, REQUEST_DELAY);
                }
            },
            error: function (jqXHR) {
                handleError('下载图片时发生严重服务器错误，导入已中止。', jqXHR);
            }
        });
    }

    function finishImportProcess() {
        submitButton.val('正在生成文章...');
        feedbackDiv.html('<div class="notice notice-info"><p><strong>步骤 3/3:</strong> 图片处理完成，正在最后整理并生成文章...</p></div>');
        $.ajax({
            url: wai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wai_finish_import',
                _ajax_nonce: wai_ajax.nonce,
                task_id: taskData.task_id
            },
            success: function (response) {
                if (response.success) {
                    var successMessage = '<div class="notice notice-success"><p>文章成功导入为草稿！ <a href="' + response.data.edit_link + '" target="_blank">点击这里查看或编辑</a></p></div>';
                    feedbackDiv.html(successMessage);
                    $('#wechat_url').val('');
                } else {
                    handleError(response.data.error || '生成文章失败。');
                }
            },
            error: function (jqXHR) {
                handleError('生成文章时发生服务器错误。', jqXHR);
            },
            complete: function() {
                submitButton.prop('disabled', false).val('开始导入');
            }
        });
    }

    function handleError(errorMessage, jqXHR) {
        var errorText = '导入失败：' + errorMessage;
        if (jqXHR && jqXHR.responseText) {
            var responseText = jqXHR.responseText;
            var phpErrorMatch = responseText.match(/<b>(Fatal error|Parse error|Warning)<\/b>:(.*?) in <b>(.*?)<\/b> on line <b>(\d+)<\/b>/);
            if(phpErrorMatch){
                errorText += '<br><small>服务器调试信息: ' + phpErrorMatch[0] + '</small>';
            } else {
                errorText += '<br><small>服务器响应(部分): ' + responseText.substring(0, 300) + '</small>';
            }
        }
        feedbackDiv.html('<div class="notice notice-error"><p>' + errorText + '</p></div>');
        submitButton.prop('disabled', false).val('开始导入');
    }
});
